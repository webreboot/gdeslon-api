<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Integration\Infrastructure\Yml;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Catalog\Offer;
use Webreboot\GdeSlon\Exception\FileException;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Exception\UnexpectedResponseException;
use Webreboot\GdeSlon\Exception\UnsupportedFormatException;
use Webreboot\GdeSlon\Infrastructure\Yml\YmlProductFeed;
use Webreboot\GdeSlon\Tests\Support\Fixtures;
use Webreboot\GdeSlon\Tests\Support\ZipBuilder;

final class YmlProductFeedTest extends TestCase
{
    private const HEAD = "<?xml version='1.0' encoding='utf-8'?>\n<!DOCTYPE yml_catalog SYSTEM \"shops.dtd\">\n"
        . '<yml_catalog date="2026-10-13 21:50"><shop><name>GdeSlon.ru</name><url>https://www.gdeslon.ru/</url>'
        . '<currencies><currency id="RUR" rate="1"/></currencies><categories><category id="1144"><![CDATA[Брюки]]></category></categories>';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/gdeslon-feed-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function testHeaderIsReadOnOpen(): void
    {
        $feed = YmlProductFeed::open($this->fixture());

        self::assertEquals(new \DateTimeImmutable('2026-10-13 21:50', new \DateTimeZone('Europe/Moscow')), $feed->generatedAt());
        self::assertSame('Europe/Moscow', $feed->generatedAt()?->getTimezone()->getName());
        self::assertCount(27, $feed->categories());
        self::assertSame('Нижнее белье', $feed->categories()->get(1128)->name());
        self::assertSame([], $feed->skipped());
        self::assertSame(0, $feed->skippedCount());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badDates(): iterable
    {
        yield 'мусор' => ['вчера'];
        yield 'пусто' => [''];
        yield 'несуществующая дата' => ['2026-02-30 10:00'];
    }

    #[DataProvider('badDates')]
    public function testBadDateIsNull(string $date): void
    {
        $feed = YmlProductFeed::open($this->write(str_replace('date="2026-10-13 21:50"', 'date="' . $date . '"', self::HEAD) . '<offers/></shop></yml_catalog>'));

        self::assertNull($feed->generatedAt());
    }

    public function testDateWithSecondsIsAccepted(): void
    {
        $feed = YmlProductFeed::open($this->write(str_replace('21:50', '21:50:07', self::HEAD) . '<offers/></shop></yml_catalog>'));

        self::assertSame('2026-10-13 21:50:07', $feed->generatedAt()?->format('Y-m-d H:i:s'));
    }

    public function testOffersAreReadAndZeroPriceIsSkipped(): void
    {
        $feed = YmlProductFeed::open($this->fixture());

        $offers = iterator_to_array($feed->offers(), false);

        self::assertCount(21, $offers);
        self::assertContainsOnlyInstancesOf(Offer::class, $offers);
        self::assertSame('86276:3080332', (string) $offers[0]->key());
        self::assertSame(['оффер «5413392713383127483»: цена 0, оффер пропущен'], $feed->skipped());
        self::assertSame(1, $feed->skippedCount());
    }

    public function testOffersCanBeReadAgain(): void
    {
        $feed = YmlProductFeed::open($this->fixture());

        $first = array_map(static fn (Offer $o): string => (string) $o->key(), iterator_to_array($feed->offers(), false));
        $second = array_map(static fn (Offer $o): string => (string) $o->key(), iterator_to_array($feed->offers(), false));

        self::assertSame($first, $second);
        self::assertSame(1, $feed->skippedCount());
    }

    public function testBrokenOfferIsSkippedWithReason(): void
    {
        $xml = str_replace('<categoryId>1924</categoryId><price>9990.00</price>', '<categoryId>1924</categoryId><price>9 990</price>', Fixtures::read('feeds/feed.xml'));
        $feed = YmlProductFeed::open($this->write($xml));

        $offers = iterator_to_array($feed->offers(), false);

        self::assertCount(20, $offers);
        self::assertSame(2, $feed->skippedCount());
        self::assertStringStartsWith('оффер «16803854530557861917»: поле price', $feed->skipped()[0]);
    }

    public function testHtmlEntityOutsideCdataKeepsOfferAndTraversal(): void
    {
        $feed = YmlProductFeed::open($this->write(self::HEAD . '<offers>'
            . self::offer(1)
            . str_replace('<name>x</name>', '<name>x</name><vendor>&laquo;Мода&raquo;&nbsp;&amp;&#160;Ко</vendor>', self::offer(2))
            . self::offer(3)
            . '</offers></shop></yml_catalog>'));

        $offers = iterator_to_array($feed->offers(), false);

        self::assertSame(['1', '2', '3'], array_map(static fn (Offer $o): string => $o->id(), $offers));
        self::assertSame("«Мода»\u{00A0}&\u{00A0}Ко", $offers[1]->vendor());
        self::assertSame(0, $feed->skippedCount());
    }

    public function testUnknownEntitySkipsOnlyThatOffer(): void
    {
        $feed = YmlProductFeed::open($this->write(self::HEAD . '<offers>'
            . self::offer(1)
            . str_replace('<name>x</name>', '<name>x &nosuchentity; y</name>', self::offer(2))
            . self::offer(3)
            . '</offers></shop></yml_catalog>'));

        $offers = iterator_to_array($feed->offers(), false);

        self::assertSame(['1', '3'], array_map(static fn (Offer $o): string => $o->id(), $offers));
        self::assertSame(['оффер «2»: ошибка XML в записи'], $feed->skipped());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function prologsWhereUndeclaredEntityIsFatal(): iterable
    {
        yield 'без DOCTYPE' => ["<?xml version='1.0' encoding='utf-8'?>\n<yml_catalog date=\"2026-10-13 21:50\"><shop>"];
        yield 'standalone' => ["<?xml version='1.0' encoding='utf-8' standalone='yes'?>\n<!DOCTYPE yml_catalog SYSTEM \"shops.dtd\">\n<yml_catalog date=\"2026-10-13 21:50\"><shop>"];
    }

    #[DataProvider('prologsWhereUndeclaredEntityIsFatal')]
    public function testFatalUndeclaredEntityDoesNotSilentlyTruncateTraversal(string $prolog): void
    {
        $feed = YmlProductFeed::open($this->write($prolog . '<offers>' . self::offers(1, 3000)
            . str_replace('<name>x</name>', '<name>a&nbsp;b</name>', self::offer(3001))
            . self::offers(3002, 6000) . '</offers></shop></yml_catalog>'));
        $read = 0;

        try {
            foreach ($feed->offers() as $offer) {
                $read++;
            }
            self::fail(sprintf('ожидалось исключение, прочитано %d', $read));
        } catch (UnexpectedResponseException $e) {
            self::assertMatchesRegularExpression('/ошибка XML в строке \d+/u', $e->getMessage());
        }
        self::assertLessThan(6000, $read);
    }

    public function testUndeclaredEntityWithExternalDtdKeepsAllOffers(): void
    {
        $feed = YmlProductFeed::open($this->write(self::HEAD . '<offers>' . self::offers(1, 3000)
            . str_replace('<name>x</name>', '<name>a&nbsp;b</name>', self::offer(3001))
            . self::offers(3002, 6000) . '</offers></shop></yml_catalog>'));

        $offers = iterator_to_array($feed->offers(), false);

        self::assertCount(6000, $offers);
        self::assertSame("a\u{00A0}b", $offers[3000]->name());
        self::assertSame(0, $feed->skippedCount());
    }

    public function testLaterCorruptionIsReportedAfterRecoveredEntity(): void
    {
        $feed = YmlProductFeed::open($this->write(self::HEAD . '<offers>' . self::offers(1, 9)
            . str_replace('<name>x</name>', '<name>a&nbsp;b</name>', self::offer(10))
            . self::offers(11, 19)
            . str_replace('<name>x</name>', '<name>x</nam>', self::offer(20))
            . self::offers(21, 30) . '</offers></shop></yml_catalog>'));

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('mismatch');

        iterator_to_array($feed->offers(), false);
    }

    public function testUndeclaredEntityInCategoriesWithoutDoctypeReportsXmlError(): void
    {
        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessageMatches('/ошибка XML в строке \d+/u');

        YmlProductFeed::open($this->write("<?xml version='1.0'?>\n<yml_catalog><shop><categories><category id=\"1\">a&nbsp;b</category></categories><offers/></shop></yml_catalog>"));
    }

    public function testHtmlEntityInCategoryName(): void
    {
        $head = str_replace('<![CDATA[Брюки]]>', 'Брюки&nbsp;и&nbsp;шорты', self::HEAD);
        $feed = YmlProductFeed::open($this->write($head . '<offers/></shop></yml_catalog>'));

        self::assertSame("Брюки\u{00A0}и\u{00A0}шорты", $feed->categories()->get(1144)->name());
    }

    public function testFileRemovedAfterOpenFailsWithPackageException(): void
    {
        $path = $this->fixture();
        $feed = YmlProductFeed::open($path);
        unlink($path);
        set_error_handler(static function (int $severity, string $message): never {
            throw new \ErrorException($message, 0, $severity);
        });

        try {
            iterator_to_array($feed->offers(), false);
            self::fail('ожидалось исключение');
        } catch (FileException $e) {
            self::assertStringNotContainsString($this->dir, $e->getMessage());
        } finally {
            restore_error_handler();
        }
    }

    public function testFileReplacedAfterOpenIsDetected(): void
    {
        $path = $this->fixture();
        $feed = YmlProductFeed::open($path);
        file_put_contents($path, str_replace('2026-10-13 21:50', '2026-10-14 21:50', Fixtures::read('feeds/feed.xml')) . "\n");
        touch($path, time() + 60);

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('выгрузка изменилась после openFeed()');

        iterator_to_array($feed->offers(), false);
    }

    public function testInterruptedTraversalRestoresLibxmlStateAndCanRestart(): void
    {
        $feed = YmlProductFeed::open($this->fixture());
        $previous = libxml_use_internal_errors(false);

        try {
            foreach ($feed->offers() as $offer) {
                self::assertFalse(libxml_use_internal_errors(), 'состояние libxml вызывающего должно быть восстановлено до yield');
                break;
            }
            self::assertFalse(libxml_use_internal_errors());
            self::assertCount(21, iterator_to_array($feed->offers(), false));
            self::assertFalse(libxml_use_internal_errors());
        } finally {
            libxml_use_internal_errors($previous);
        }
    }

    public function testEmptyOffers(): void
    {
        $feed = YmlProductFeed::open($this->write(self::HEAD . '<offers/></shop></yml_catalog>'));

        self::assertSame([], iterator_to_array($feed->offers(), false));
        self::assertSame(0, $feed->skippedCount());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function notFeeds(): iterable
    {
        yield 'нет offers' => [self::HEAD . '</shop></yml_catalog>', 'нет элемента «yml_catalog/shop/offers»'];
        yield 'другой корень' => ['<?xml version="1.0"?><catalog><shop><offers/></shop></catalog>', 'корневой элемент «catalog» вместо «yml_catalog»'];
        yield 'страница входа' => ['<!DOCTYPE html><html><head><title>Вход</title></head><body><form>Пароль</form></body></html>', 'DOCTYPE'];
        yield 'html без doctype' => ['<html><body><p>Пароль<br></p></body></html>', 'Выгрузка (49 байт): '];
        yield 'xhtml без doctype' => ['<html><body><p>Пароль</p></body></html>', 'корневой элемент «html» вместо «yml_catalog»'];
        yield 'пустой файл' => ['', 'пустой файл'];
        yield 'не xml' => ['Пароль: ещё раз', 'не XML'];
    }

    #[DataProvider('notFeeds')]
    public function testNotFeedIsRejectedWithoutContent(string $content, string $reason): void
    {
        try {
            $feed = YmlProductFeed::open($this->write($content));
            iterator_to_array($feed->offers(), false);
            self::fail('ожидалось исключение');
        } catch (UnexpectedResponseException $e) {
            self::assertStringContainsString($reason, $e->getMessage());
            self::assertStringNotContainsString('Пароль', $e->getMessage());
            self::assertStringNotContainsString($this->dir, $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unsupportedFormats(): iterable
    {
        yield 'zip' => [(new ZipBuilder())->add('feed.xml', '<yml_catalog/>')->build(), 'unpackFeed'];
        yield 'gzip' => [(string) gzencode('<yml_catalog/>'), 'gzip'];
        yield 'csv' => ["id;name;price\n1;Платье;1499\n", 'CSV'];
    }

    #[DataProvider('unsupportedFormats')]
    public function testUnsupportedFormatIsRejected(string $content, string $hint): void
    {
        $this->expectException(UnsupportedFormatException::class);
        $this->expectExceptionMessage($hint);

        YmlProductFeed::open($this->write($content));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function dangerousXml(): iterable
    {
        yield 'XXE' => ["<?xml version='1.0'?>\n<!DOCTYPE yml_catalog [<!ENTITY xxe SYSTEM \"file:///etc/passwd\">]>\n<yml_catalog><shop><offers><offer id=\"1\"><name>&xxe;</name></offer></offers></shop></yml_catalog>"];
        yield 'billion laughs' => ["<?xml version='1.0'?>\n<!DOCTYPE yml_catalog [<!ENTITY a \"aaaaaaaaaa\"><!ENTITY b \"&a;&a;&a;&a;&a;&a;&a;&a;&a;&a;\"><!ENTITY c \"&b;&b;&b;&b;&b;&b;&b;&b;&b;&b;\">]>\n<yml_catalog date=\"&c;\"><shop><offers/></shop></yml_catalog>"];
        yield 'внешний DTD по сети' => ["<?xml version='1.0'?>\n<!DOCTYPE yml_catalog SYSTEM \"http://127.0.0.1:1/evil.dtd\">\n<yml_catalog><shop><offers/></shop></yml_catalog>"];
        yield 'DOCTYPE с подмножеством после разрешённого' => ["<?xml version='1.0'?>\n<!DOCTYPE yml_catalog SYSTEM \"shops.dtd\" [<!ENTITY x \"y\">]>\n<yml_catalog><shop><offers/></shop></yml_catalog>"];
    }

    #[DataProvider('dangerousXml')]
    public function testDangerousDoctypeIsRejectedWithoutExpansionOrNetwork(string $xml): void
    {
        $started = microtime(true);
        try {
            $feed = YmlProductFeed::open($this->write($xml));
            iterator_to_array($feed->offers(), false);
            self::fail('ожидалось исключение');
        } catch (UnexpectedResponseException $e) {
            self::assertStringContainsString('DOCTYPE', $e->getMessage());
            self::assertStringNotContainsString('root:', $e->getMessage());
        }
        self::assertLessThan(1.0, microtime(true) - $started);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPaths(): iterable
    {
        yield 'http' => ['http://127.0.0.1:1/feed.xml'];
        yield 'php filter' => ['php://filter/resource=/etc/passwd'];
        yield 'phar' => ['phar://feed.phar/feed.xml'];
        yield 'compress.zlib' => ['compress.zlib:///etc/passwd'];
    }

    #[DataProvider('invalidPaths')]
    public function testStreamWrappersAreRejected(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);

        YmlProductFeed::open($path);
    }

    public function testMissingFileAndDirectoryAreRejected(): void
    {
        foreach ([$this->dir . '/missing.xml', $this->dir] as $path) {
            try {
                YmlProductFeed::open($path);
                self::fail('ожидалось исключение');
            } catch (InvalidArgumentException $e) {
                self::assertStringNotContainsString($this->dir, $e->getMessage());
            }
        }
    }

    public function testTruncatedFileYieldsReadOffersThenFails(): void
    {
        $xml = Fixtures::read('feeds/feed.xml');
        $path = $this->write(substr($xml, 0, (int) (strlen($xml) * 0.6)));
        $feed = YmlProductFeed::open($path);
        $read = 0;

        try {
            foreach ($feed->offers() as $offer) {
                $read++;
            }
            self::fail('ожидалось исключение');
        } catch (UnexpectedResponseException $e) {
            self::assertMatchesRegularExpression('/ошибка XML в строке \d+/u', $e->getMessage());
            self::assertStringNotContainsString('CDATA', $e->getMessage());
        }
        self::assertGreaterThan(5, $read);
    }

    public function testSkippedReasonsAreCappedButCounted(): void
    {
        $offers = '';
        for ($i = 1; $i <= 151; $i++) {
            $offers .= self::offer($i, $i === 151 ? '10.00' : '0.00');
        }
        $feed = YmlProductFeed::open($this->write(self::HEAD . "<offers>$offers</offers></shop></yml_catalog>"));

        self::assertCount(1, iterator_to_array($feed->offers(), false));
        self::assertCount(100, $feed->skipped());
        self::assertSame(150, $feed->skippedCount());
    }

    public function testMemoryDoesNotGrowWithOfferCount(): void
    {
        $path = $this->dir . '/big.xml';
        $file = fopen($path, 'wb');
        self::assertNotFalse($file);
        fwrite($file, self::HEAD . '<offers>');
        for ($i = 1; $i <= 30000; $i++) {
            fwrite($file, sprintf(
                '<offer id="%d" merchant_id="1" article="a%d" gs_category_id="1144"><picture>//imgng.gdeslon.ru/%s/big.jpg</picture><name><![CDATA[Платье %s]]></name><description><![CDATA[%s]]></description><param name="&#x426;&#x432;&#x435;&#x442;"><![CDATA[Серый]]></param><url>https://xf.gdeslon.ru/cm/0a1b2c3d4e/?mid=1&amp;goto=x</url><currencyId>RUR</currencyId><categoryId>1144</categoryId><price>1499.00</price></offer>',
                $i,
                $i,
                bin2hex(random_bytes(8)),
                bin2hex(random_bytes(8)),
                str_repeat('Описание. ', 30),
            ));
        }
        fwrite($file, '</offers></shop></yml_catalog>');
        fclose($file);

        $script = $this->dir . '/read.php';
        file_put_contents($script, sprintf(
            '<?php require %s; $feed = %s::open(%s); $before = memory_get_usage(); $n = 0; foreach ($feed->offers() as $offer) { $n++; } echo $n, " ", memory_get_peak_usage() - $before;',
            var_export(dirname(__DIR__, 4) . '/vendor/autoload.php', true),
            YmlProductFeed::class,
            var_export($path, true),
        ));
        $output = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script));
        [$count, $peak] = array_map('intval', explode(' ', trim($output)) + [1 => '-1']);

        self::assertSame(30000, $count, $output);
        self::assertGreaterThanOrEqual(0, $peak, $output);
        self::assertLessThan(2 * 1024 * 1024, $peak);
    }

    private static function offers(int $from, int $to): string
    {
        return implode('', array_map(static fn (int $id): string => self::offer($id), range($from, $to)));
    }

    private static function offer(int $id, string $price = '10.00'): string
    {
        return sprintf(
            '<offer id="%d" merchant_id="1" article="a%d" gs_category_id="1144"><name>x</name><url>https://xf.gdeslon.ru/cm/0a1b2c3d4e/?mid=1</url><currencyId>RUR</currencyId><categoryId>1144</categoryId><price>%s</price></offer>',
            $id,
            $id,
            $price,
        );
    }

    private function fixture(): string
    {
        return $this->write(Fixtures::read('feeds/feed.xml'));
    }

    private function write(string $content): string
    {
        $path = $this->dir . '/feed-' . bin2hex(random_bytes(3)) . '.xml';
        file_put_contents($path, $content);

        return $path;
    }
}

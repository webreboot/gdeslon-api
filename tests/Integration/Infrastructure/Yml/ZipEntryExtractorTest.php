<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Integration\Infrastructure\Yml;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Exception\FileException;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Exception\UnexpectedResponseException;
use Webreboot\GdeSlon\Exception\UnsupportedFormatException;
use Webreboot\GdeSlon\Infrastructure\Yml\ZipEntryExtractor;
use Webreboot\GdeSlon\Tests\Support\Fixtures;
use Webreboot\GdeSlon\Tests\Support\ZipBuilder;

final class ZipEntryExtractorTest extends TestCase
{
    private const ENTRY = '6mOMsecretLookingEntryName0123456789abcd.xml';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/gdeslon-zip-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        @rmdir($this->dir . '/readonly');
        rmdir($this->dir);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function methods(): iterable
    {
        yield 'deflate' => [ZipBuilder::DEFLATED];
        yield 'stored' => [ZipBuilder::STORED];
    }

    #[DataProvider('methods')]
    public function testSingleEntryIsExtractedByteForByte(int $method): void
    {
        $xml = Fixtures::read('feeds/feed.xml');
        $zip = (new ZipBuilder())->add(self::ENTRY, $xml, $method)->writeTo($this->dir . '/feed.zip');

        $bytes = (new ZipEntryExtractor())->extract($zip, $this->dir . '/feed.xml');

        self::assertSame(strlen($xml), $bytes);
        self::assertSame($xml, file_get_contents($this->dir . '/feed.xml'));
        self::assertSame(['feed.xml', 'feed.zip'], self::files($this->dir));
    }

    public function testLocalExtraFieldDifferentFromCentralIsHonoured(): void
    {
        $zip = (new ZipBuilder())->add(self::ENTRY, '<yml_catalog/>', localExtra: str_repeat("\x00", 28), centralExtra: str_repeat("\x00", 24))->writeTo($this->dir . '/feed.zip');

        (new ZipEntryExtractor())->extract($zip, $this->dir . '/feed.xml');

        self::assertSame('<yml_catalog/>', file_get_contents($this->dir . '/feed.xml'));
    }

    public function testDataDescriptorWithZeroLocalSizesUsesCentralDirectory(): void
    {
        $xml = Fixtures::read('feeds/feed.xml');
        $zip = (new ZipBuilder())->add(self::ENTRY, $xml, dataDescriptor: true)->writeTo($this->dir . '/feed.zip');

        (new ZipEntryExtractor())->extract($zip, $this->dir . '/feed.xml');

        self::assertSame($xml, file_get_contents($this->dir . '/feed.xml'));
    }

    #[RequiresPhpExtension('zip')]
    public function testArchiveBuiltByZipArchive(): void
    {
        $xml = Fixtures::read('feeds/feed.xml');
        $archive = new \ZipArchive();
        self::assertTrue($archive->open($this->dir . '/feed.zip', \ZipArchive::CREATE));
        $archive->addFromString(self::ENTRY, $xml);
        $archive->close();

        (new ZipEntryExtractor())->extract($this->dir . '/feed.zip', $this->dir . '/feed.xml');

        self::assertSame($xml, file_get_contents($this->dir . '/feed.xml'));
    }

    public function testForeignPartFileIsNotTouched(): void
    {
        file_put_contents($this->dir . '/feed.xml.part', 'чужая распаковка');
        $zip = (new ZipBuilder())->add(self::ENTRY, '<yml_catalog/>')->writeTo($this->dir . '/feed.zip');

        (new ZipEntryExtractor())->extract($zip, $this->dir . '/feed.xml');

        self::assertSame('чужая распаковка', file_get_contents($this->dir . '/feed.xml.part'));
        self::assertSame('<yml_catalog/>', file_get_contents($this->dir . '/feed.xml'));
        self::assertSame(['feed.xml', 'feed.xml.part', 'feed.zip'], self::files($this->dir));
    }

    public function testStalePartFilesOfSameTargetAreRemoved(): void
    {
        $old = ['feed.xml.part0123456789ab', 'feed.xml.partba9876543210', 'other.xml.part0123456789ab', 'feed.xml.part.bak', 'feed.xml.partial', 'feed.xml.part'];
        foreach ([...$old, 'feed.xml.partfedcba987654'] as $name) {
            file_put_contents($this->dir . '/' . $name, 'x');
        }
        foreach ($old as $name) {
            touch($this->dir . '/' . $name, time() - 7200);
        }
        $zip = (new ZipBuilder())->add(self::ENTRY, '<yml_catalog/>')->writeTo($this->dir . '/feed.zip');

        (new ZipEntryExtractor())->extract($zip, $this->dir . '/feed.xml');

        self::assertSame(
            ['feed.xml', 'feed.xml.part', 'feed.xml.part.bak', 'feed.xml.partfedcba987654', 'feed.xml.partial', 'feed.zip', 'other.xml.part0123456789ab'],
            self::files($this->dir),
        );
    }

    public function testFailedExtractionLeavesNoTemporaryFiles(): void
    {
        $zip = (new ZipBuilder())->add(self::ENTRY, '<yml_catalog/>', crc: 1)->writeTo($this->dir . '/feed.zip');

        try {
            (new ZipEntryExtractor())->extract($zip, $this->dir . '/feed.xml');
            self::fail('ожидалось исключение');
        } catch (UnexpectedResponseException) {
        }

        self::assertSame(['feed.zip'], self::files($this->dir));
    }

    public function testExtractedFileIsReadableByOthersLikeRegularFile(): void
    {
        $zip = (new ZipBuilder())->add(self::ENTRY, '<yml_catalog/>')->writeTo($this->dir . '/feed.zip');
        $previous = umask(0022);

        try {
            (new ZipEntryExtractor())->extract($zip, $this->dir . '/feed.xml');
        } finally {
            umask($previous);
        }

        self::assertSame(0644, fileperms($this->dir . '/feed.xml') & 0777);
    }

    public function testExistingTargetIsReplaced(): void
    {
        file_put_contents($this->dir . '/feed.xml', 'old');
        $zip = (new ZipBuilder())->add(self::ENTRY, '<yml_catalog/>')->writeTo($this->dir . '/feed.zip');

        (new ZipEntryExtractor())->extract($zip, $this->dir . '/feed.xml');

        self::assertSame('<yml_catalog/>', file_get_contents($this->dir . '/feed.xml'));
    }

    public function testLargeEntryIsStreamedWithBoundedMemory(): void
    {
        $xml = '';
        for ($i = 0; $i < 120000; $i++) {
            $xml .= sprintf('<offer id="%d"><name>Платье %s</name></offer>', $i, bin2hex(random_bytes(8)));
        }
        $zip = (new ZipBuilder())->add(self::ENTRY, $xml)->writeTo($this->dir . '/feed.zip');
        unset($xml);
        $script = $this->dir . '/extract.php';
        file_put_contents($script, sprintf(
            '<?php require %s; $before = memory_get_usage(); $bytes = (new %s())->extract(%s, %s); echo $bytes, " ", memory_get_peak_usage() - $before;',
            var_export(dirname(__DIR__, 4) . '/vendor/autoload.php', true),
            ZipEntryExtractor::class,
            var_export($zip, true),
            var_export($this->dir . '/feed.xml', true),
        ));

        $output = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script));
        [$bytes, $peak] = array_map('intval', explode(' ', trim($output)) + [1 => '-1']);

        self::assertGreaterThan(8_000_000, $bytes, $output);
        self::assertGreaterThanOrEqual(0, $peak, $output);
        self::assertLessThan(2 * 1024 * 1024, $peak);
    }

    /**
     * @return iterable<string, array{\Closure(ZipBuilder): ZipBuilder, class-string<\Throwable>, string}>
     */
    public static function brokenArchives(): iterable
    {
        yield 'две записи' => [
            static fn (ZipBuilder $b): ZipBuilder => $b->add(self::ENTRY, '<a/>')->add('second.xml', '<b/>'),
            UnexpectedResponseException::class,
            'в архиве 2 записи, ожидалась одна',
        ];
        yield 'нет записей' => [static fn (ZipBuilder $b): ZipBuilder => $b, UnexpectedResponseException::class, 'в архиве 0 записей'];
        yield 'неверный CRC' => [
            static fn (ZipBuilder $b): ZipBuilder => $b->add(self::ENTRY, '<yml_catalog/>', crc: 1),
            UnexpectedResponseException::class,
            'контрольная сумма',
        ];
        yield 'размер не совпал' => [
            static fn (ZipBuilder $b): ZipBuilder => $b->add(self::ENTRY, '<yml_catalog/>', usize: 5),
            UnexpectedResponseException::class,
            'размер',
        ];
        yield 'шифрование' => [
            static fn (ZipBuilder $b): ZipBuilder => $b->add(self::ENTRY, '<a/>', flags: 1),
            UnsupportedFormatException::class,
            'зашифрованный',
        ];
        yield 'метод bzip2' => [
            static fn (ZipBuilder $b): ZipBuilder => $b->add(self::ENTRY, '<a/>', method: 12),
            UnsupportedFormatException::class,
            'метод сжатия 12',
        ];
        yield 'zip64' => [static fn (ZipBuilder $b): ZipBuilder => $b->add(self::ENTRY, '<a/>')->zip64(), UnsupportedFormatException::class, 'zip64'];
        yield 'битый deflate' => [
            static fn (ZipBuilder $b): ZipBuilder => $b->add(self::ENTRY, '<yml_catalog/>', payload: "\xFF\xFE not deflate"),
            UnexpectedResponseException::class,
            'архив',
        ];
    }

    /**
     * @param \Closure(ZipBuilder): ZipBuilder $build
     * @param class-string<\Throwable> $exception
     */
    #[DataProvider('brokenArchives')]
    public function testBrokenArchiveLeavesTargetUntouched(\Closure $build, string $exception, string $reason): void
    {
        file_put_contents($this->dir . '/feed.xml', 'old');
        $zip = $build(new ZipBuilder())->writeTo($this->dir . '/feed.zip');

        try {
            (new ZipEntryExtractor())->extract($zip, $this->dir . '/feed.xml');
            self::fail('ожидалось исключение');
        } catch (\Throwable $e) {
            self::assertInstanceOf($exception, $e);
            self::assertStringContainsString($reason, $e->getMessage());
            self::assertStringNotContainsString(self::ENTRY, $e->getMessage());
            self::assertStringNotContainsString('6mOM', $e->getMessage());
        }
        self::assertSame('old', file_get_contents($this->dir . '/feed.xml'));
        self::assertSame(['feed.xml', 'feed.zip'], self::files($this->dir));
    }

    public function testTruncatedArchiveIsRejected(): void
    {
        $bytes = (new ZipBuilder())->add(self::ENTRY, Fixtures::read('feeds/feed.xml'))->build();
        file_put_contents($this->dir . '/feed.zip', substr($bytes, 0, intdiv(strlen($bytes), 2)));

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('не zip-архив или архив обрезан');

        (new ZipEntryExtractor())->extract($this->dir . '/feed.zip', $this->dir . '/feed.xml');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notZip(): iterable
    {
        yield 'xml' => ['<?xml version="1.0"?><yml_catalog/>'];
        yield 'gzip' => [(string) gzencode('<yml_catalog/>')];
        yield 'пусто' => [''];
        yield 'html' => ['<html><body>Войти</body></html>'];
    }

    #[DataProvider('notZip')]
    public function testNotZipIsRejected(string $content): void
    {
        file_put_contents($this->dir . '/feed.zip', $content);

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('не zip-архив или архив обрезан');

        (new ZipEntryExtractor())->extract($this->dir . '/feed.zip', $this->dir . '/feed.xml');
    }

    public function testEntryLargerThanLimitIsRejectedBeforeExtraction(): void
    {
        $zip = (new ZipBuilder())->add(self::ENTRY, str_repeat('a', 2000))->writeTo($this->dir . '/feed.zip');

        try {
            (new ZipEntryExtractor(maxBytes: 1000))->extract($zip, $this->dir . '/feed.xml');
            self::fail('ожидалось исключение');
        } catch (UnexpectedResponseException $e) {
            self::assertStringContainsString('больше лимита 1000 байт', $e->getMessage());
        }
        self::assertSame(['feed.zip'], self::files($this->dir));
    }

    public function testHighlyCompressedEntryExceedingDeclaredSizeIsRejected(): void
    {
        $zip = (new ZipBuilder())->add(self::ENTRY, str_repeat('a', 20_000_000), usize: 1000)->writeTo($this->dir . '/feed.zip');

        try {
            (new ZipEntryExtractor())->extract($zip, $this->dir . '/feed.xml');
            self::fail('ожидалось исключение');
        } catch (UnexpectedResponseException $e) {
            self::assertStringContainsString('не совпал с заявленным (1000)', $e->getMessage());
        }
        self::assertSame(['feed.zip'], self::files($this->dir));
    }

    public function testDeflateWithoutZlibIsUnsupported(): void
    {
        $zip = (new ZipBuilder())->add(self::ENTRY, '<a/>')->writeTo($this->dir . '/feed.zip');

        $this->expectException(UnsupportedFormatException::class);
        $this->expectExceptionMessage('нужно расширение zlib');

        (new ZipEntryExtractor(zlibAvailable: false))->extract($zip, $this->dir . '/feed.xml');
    }

    public function testStoredWithoutZlibWorks(): void
    {
        $zip = (new ZipBuilder())->add(self::ENTRY, '<a/>', ZipBuilder::STORED)->writeTo($this->dir . '/feed.zip');

        (new ZipEntryExtractor(zlibAvailable: false))->extract($zip, $this->dir . '/feed.xml');

        self::assertSame('<a/>', file_get_contents($this->dir . '/feed.xml'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidSources(): iterable
    {
        yield 'нет файла' => ['missing.zip'];
        yield 'каталог' => ['.'];
        yield 'обёртка php' => ['php://memory'];
        yield 'phar' => ['phar://x.zip/a'];
    }

    #[DataProvider('invalidSources')]
    public function testInvalidSourceIsRejected(string $source): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ZipEntryExtractor())->extract(str_contains($source, '://') ? $source : $this->dir . '/' . $source, $this->dir . '/feed.xml');
    }

    public function testTargetWithStreamWrapperIsRejected(): void
    {
        $zip = (new ZipBuilder())->add(self::ENTRY, '<a/>')->writeTo($this->dir . '/feed.zip');

        $this->expectException(InvalidArgumentException::class);

        (new ZipEntryExtractor())->extract($zip, 'php://memory');
    }

    /**
     * @return list<string>
     */
    private static function files(string $dir): array
    {
        $files = array_map('basename', glob($dir . '/{,.}*', GLOB_BRACE) ?: []);
        $files = array_values(array_filter($files, static fn (string $f): bool => !in_array($f, ['.', '..'], true)));
        sort($files);

        return $files;
    }

    public function testUnwritableTargetDirectoryIsFileException(): void
    {
        $zip = (new ZipBuilder())->add(self::ENTRY, '<a/>')->writeTo($this->dir . '/feed.zip');

        try {
            (new ZipEntryExtractor())->extract($zip, $this->dir . '/missing-dir/feed.xml');
            self::fail('ожидалось исключение');
        } catch (FileException $e) {
            self::assertStringNotContainsString(self::ENTRY, $e->getMessage());
        }
    }
}

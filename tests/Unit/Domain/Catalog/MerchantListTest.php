<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Domain\Catalog;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Catalog\Merchant;
use Webreboot\GdeSlon\Domain\Catalog\MerchantCategory;
use Webreboot\GdeSlon\Domain\Catalog\MerchantId;
use Webreboot\GdeSlon\Domain\Catalog\MerchantList;
use Webreboot\GdeSlon\Domain\Catalog\MerchantNotFoundException;
use Webreboot\GdeSlon\Exception\GdeSlonException;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;

final class MerchantListTest extends TestCase
{
    public function testEmptyList(): void
    {
        $list = new MerchantList([]);

        self::assertCount(0, $list);
        self::assertSame([], $list->all());
        self::assertNull($list->find(1));
        self::assertFalse($list->has(1));
        self::assertSame([], $list->categories());
        self::assertSame([], $list->skipped());
    }

    public function testSkippedRecordsAreReported(): void
    {
        $list = new MerchantList([self::merchant(1, 'x', 'https://x.ru/')], ['магазин «7»: нет поля url']);

        self::assertCount(1, $list);
        self::assertSame(['магазин «7»: нет поля url'], $list->skipped());
    }

    public function testGet(): void
    {
        $list = self::list();

        self::assertSame('komus.ru', $list->get(115651)->name());
        self::assertSame($list->get(115651), $list->get(new MerchantId(115651)));
        self::assertTrue($list->has(new MerchantId(82012)));
    }

    #[DataProvider('missingIds')]
    public function testGetUnknownMerchant(int $id): void
    {
        try {
            self::list()->get($id);
            self::fail('Ожидалось исключение');
        } catch (MerchantNotFoundException $e) {
            self::assertInstanceOf(GdeSlonException::class, $e);
            self::assertSame($id, $e->merchantId());
            self::assertStringContainsString((string) $id, $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function missingIds(): iterable
    {
        yield 'нет такого' => [999];
        yield 'невозможный' => [0];
    }

    public function testDuplicateIdIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('115651');

        new MerchantList([self::merchant(115651, 'komus.ru', 'https://www.komus.ru/'), self::merchant(115651, 'komus 2', 'https://komus.ru/')]);
    }

    public function testOrderAndIteration(): void
    {
        $list = self::list();

        self::assertSame([115651, 82012, 112032, 1, 2], array_map(static fn (Merchant $m): int => $m->id()->value(), $list->all()));
        self::assertSame($list->all(), iterator_to_array($list, false));
        self::assertCount(5, $list);
    }

    #[DataProvider('domains')]
    public function testFindByDomain(string $query, int ...$expected): void
    {
        self::assertSame($expected, self::ids(self::list()->findByDomain($query)));
    }

    /**
     * @return iterable<string, array<int, int|string>>
     */
    public static function domains(): iterable
    {
        yield 'хост' => ['komus.ru', 115651];
        yield 'URL с www и путём' => ['https://www.komus.ru/catalog/x', 115651];
        yield 'регистр' => ['KOMUS.RU', 115651];
        yield 'два магазина одного хоста' => ['mts.ru', 1, 2];
        yield 'неизвестный' => ['unknown.ru'];
    }

    public function testFindByEmptyDomainIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::list()->findByDomain(' ');
    }

    public function testSearch(): void
    {
        $list = self::list();

        self::assertSame([82012], self::ids($list->search('aliexp')));
        self::assertSame([82012], self::ids($list->search('ALI')));
        $cyrillic = new MerchantList([self::merchant(7, 'Всё для шитья', 'https://shitie.ru/')]);
        self::assertSame([7], self::ids($cyrillic->search('ВСЁ ДЛЯ')), 'кириллица без учёта регистра');
        self::assertSame([112032], self::ids($list->search('5ka.ru (Android')));
        self::assertSame([1, 2], self::ids($list->search('mts')));
        self::assertSame([], $list->search('нет такого'));
    }

    public function testEmptySearchIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::list()->search('  ');
    }

    public function testCategoriesAndFilter(): void
    {
        $list = self::list();

        self::assertSame([115651, 112032], self::ids($list->inCategory(50)));
        self::assertSame([[5, 'Одежда и обувь'], [50, 'Обучение']], array_map(
            static fn (MerchantCategory $c): array => [$c->id(), $c->name()],
            $list->categories(),
        ));
        self::assertSame([82012], self::ids($list->filter(static fn (Merchant $m): bool => str_starts_with($m->url(), 'http://'))));
    }

    private static function list(): MerchantList
    {
        $education = new MerchantCategory(50, 'Обучение');
        $clothes = new MerchantCategory(5, 'Одежда и обувь');

        return new MerchantList([
            self::merchant(115651, 'komus.ru', 'https://www.komus.ru/', [$education]),
            self::merchant(82012, 'AliExpress WW', 'http://aliexpress.com/', [$clothes]),
            self::merchant(112032, '5ka.ru (Android & IOS)', 'https://5ka.ru/', [$education]),
            self::merchant(1, 'payment.mts.ru/cyber', 'https://mts.ru/cyber'),
            self::merchant(2, 'mts.ru/personal', 'https://www.mts.ru/personal'),
        ]);
    }

    /**
     * @param list<MerchantCategory> $categories
     */
    private static function merchant(int $id, string $name, string $url, array $categories = []): Merchant
    {
        return new Merchant(new MerchantId($id), $name, $url, categories: $categories);
    }

    /**
     * @param list<Merchant> $merchants
     *
     * @return list<int>
     */
    private static function ids(array $merchants): array
    {
        return array_map(static fn (Merchant $m): int => $m->id()->value(), $merchants);
    }
}

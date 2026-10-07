<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Interface\Mcp;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Catalog\OfferSort;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Interface\Mcp\Arguments;
use Webreboot\GdeSlon\Interface\Mcp\Json;
use Webreboot\GdeSlon\Interface\Mcp\Page;

final class ArgumentsTest extends TestCase
{
    public function testIntegers(): void
    {
        $arguments = self::arguments('{"a":5,"b":5.0,"c":null}');

        self::assertSame(5, $arguments->int('a', 1));
        self::assertSame(5, $arguments->int('b', 1), '5.0 — то же целое');
        self::assertNull($arguments->int('c', 1), 'null — не задан');
        self::assertNull($arguments->int('d', 1));
        $arguments->done();
    }

    #[DataProvider('badIntegers')]
    public function testBadIntegers(string $json): void
    {
        $arguments = self::arguments('{"merchant_id":' . $json . '}');
        $arguments->int('merchant_id', 1);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('merchant_id');
        $arguments->done();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badIntegers(): iterable
    {
        foreach (['"5"', '5.5', 'true', '0', '-1', '9223372036854775808', '[1]', '{}'] as $json) {
            yield $json => [$json];
        }
    }

    public function testAllErrorsAtOnce(): void
    {
        $arguments = self::arguments('{"limit":0,"sort":"partner-benefit","foo":1,"bar":2}');
        $arguments->int('limit', 1, 100);
        $arguments->choice('sort', ['price' => OfferSort::Price, 'partner_benefit' => OfferSort::PartnerBenefit]);

        try {
            $arguments->done();
            self::fail('ожидалась ошибка');
        } catch (InvalidArgumentException $e) {
            foreach (['limit', 'от 1 до 100', 'sort', 'price, partner_benefit', 'неизвестный аргумент foo', 'bar'] as $part) {
                self::assertStringContainsString($part, $e->getMessage());
            }
        }
    }

    public function testChoiceStringsListsBoolAndDate(): void
    {
        $arguments = self::arguments('{"sort":"partner_benefit","q":" платье ","ids":[1,2,2],"arts":["A-1"],"flag":true,"day":"2026-09-30"}');

        self::assertSame(OfferSort::PartnerBenefit, $arguments->choice('sort', ['price' => OfferSort::Price, 'partner_benefit' => OfferSort::PartnerBenefit]));
        self::assertSame('платье', $arguments->string('q', 100));
        self::assertSame([1, 2, 2], $arguments->intList('ids'));
        self::assertSame(['A-1'], $arguments->stringList('arts', 100));
        self::assertTrue($arguments->bool('flag'));
        self::assertSame('2026-09-30', $arguments->date('day'));
        self::assertSame([], $arguments->intList('none'));
        self::assertSame([], $arguments->choiceList('none', ['paid' => 4]));
        $arguments->done();

        $arguments = self::arguments('{"states":["confirmed","paid"]}');
        self::assertSame([3, 4], $arguments->choiceList('states', ['confirmed' => 3, 'paid' => 4]));
        $arguments->done();
    }

    #[DataProvider('badValues')]
    public function testBadValues(string $json, callable $read): void
    {
        $arguments = self::arguments($json);
        $read($arguments);

        $this->expectException(InvalidArgumentException::class);
        $arguments->done();
    }

    /**
     * @return iterable<string, array{string, callable(Arguments): mixed}>
     */
    public static function badValues(): iterable
    {
        yield 'строка не строка' => ['{"q":5}', static fn (Arguments $a) => $a->string('q', 10)];
        yield 'пустая строка' => ['{"q":"  "}', static fn (Arguments $a) => $a->string('q', 10)];
        yield 'длинная строка' => ['{"q":"' . str_repeat('я', 11) . '"}', static fn (Arguments $a) => $a->string('q', 10)];
        yield 'список не список' => ['{"ids":5}', static fn (Arguments $a) => $a->intList('ids')];
        yield 'список с объектом' => ['{"ids":{"a":1}}', static fn (Arguments $a) => $a->intList('ids')];
        yield 'плохой элемент' => ['{"ids":[1,"2"]}', static fn (Arguments $a) => $a->intList('ids')];
        yield 'длинный список' => ['{"ids":[' . implode(',', range(1, 101)) . ']}', static fn (Arguments $a) => $a->intList('ids')];
        yield 'bool не bool' => ['{"flag":"true"}', static fn (Arguments $a) => $a->bool('flag')];
        yield 'дата не дата' => ['{"day":"2026-02-30"}', static fn (Arguments $a) => $a->date('day')];
        yield 'дата не формат' => ['{"day":"30.09.2026"}', static fn (Arguments $a) => $a->date('day')];
        yield 'choice не строка' => ['{"sort":1}', static fn (Arguments $a) => $a->choice('sort', ['price' => 1])];
        yield 'choiceList с чужим' => ['{"states":["paid","foo"]}', static fn (Arguments $a) => $a->choiceList('states', ['paid' => 4])];
        yield 'choiceList не список' => ['{"states":"paid"}', static fn (Arguments $a) => $a->choiceList('states', ['paid' => 4])];
    }

    public function testPage(): void
    {
        $items = range(1, 45);

        $page = Page::of(self::arguments('{"limit":20,"offset":40}'), 10, 100);
        self::assertSame([41, 42, 43, 44, 45], $page->slice($items));
        self::assertSame(['total' => 45, 'limit' => 20, 'offset' => 40, 'next_offset' => null], $page->meta(45));

        $page = Page::of(self::arguments('{"limit":20,"offset":20}'), 10, 100);
        self::assertSame(['total' => 45, 'limit' => 20, 'offset' => 20, 'next_offset' => 40], $page->meta(45));

        $page = Page::of(self::arguments('{"offset":100}'), 10, 100);
        self::assertSame([], $page->slice($items));
        self::assertSame(['total' => 45, 'limit' => 10, 'offset' => 100, 'next_offset' => null], $page->meta(45));

        $arguments = self::arguments('{"limit":101}');
        Page::of($arguments, 10, 100);
        $this->expectException(InvalidArgumentException::class);
        $arguments->done();
    }

    private static function arguments(string $json): Arguments
    {
        $value = Json::decode($json);
        self::assertInstanceOf(\stdClass::class, $value);

        return new Arguments($value);
    }
}

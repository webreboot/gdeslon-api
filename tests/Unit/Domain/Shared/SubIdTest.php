<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Domain\Shared;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Shared\SubId;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;

final class SubIdTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function validValues(): iterable
    {
        yield 'латиница с подчёркиванием и дефисом' => ['tg_channel-42'];
        yield 'один символ' => ['a'];
        yield 'цифры' => ['0042'];
        yield 'заглавные' => ['VK_Ads'];
        yield 'ровно 64 символа' => [str_repeat('a', 64)];
        yield 'только дефис' => ['-'];
    }

    #[DataProvider('validValues')]
    public function testValidValues(string $value): void
    {
        $subId = new SubId($value);

        self::assertSame($value, $subId->value());
        self::assertSame($value, (string) $subId);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidValues(): iterable
    {
        yield 'пусто' => [''];
        yield '65 символов' => [str_repeat('a', 65)];
        yield 'пробел' => ['tg channel'];
        yield 'пробел в конце' => ['tg '];
        yield 'перевод строки в конце' => ["tg\n"];
        yield 'кириллица' => ['канал'];
        yield 'амперсанд' => ['a&b=c'];
        yield 'решётка' => ['a#b'];
        yield 'процент' => ['a%20b'];
        yield 'точка' => ['a.b'];
        yield 'плюс' => ['a+b'];
        yield 'нулевой байт' => ["a\0"];
        yield 'None из JSON-купонов' => ['None!'];
    }

    #[DataProvider('invalidValues')]
    public function testInvalidValues(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SubId($value);
    }

    public function testMessageDoesNotContainValue(): void
    {
        try {
            new SubId('секретная метка');
            self::fail('Ожидалось исключение');
        } catch (InvalidArgumentException $e) {
            self::assertStringNotContainsString('секретная', $e->getMessage());
            self::assertStringContainsString('sub_id', $e->getMessage());
            self::assertStringContainsString('64', $e->getMessage());
        }
    }

    public function testIsValidMatchesConstructor(): void
    {
        foreach (self::validValues() as [$value]) {
            self::assertTrue(SubId::isValid($value), $value);
        }
        foreach (self::invalidValues() as [$value]) {
            self::assertFalse(SubId::isValid($value), $value);
        }
        self::assertSame(64, SubId::MAX_LENGTH);
        self::assertSame(5, SubId::MAX_POSITION);
    }

    public function testEquals(): void
    {
        self::assertTrue((new SubId('site'))->equals(new SubId('site')));
        self::assertFalse((new SubId('site'))->equals(new SubId('Site')));
    }
}

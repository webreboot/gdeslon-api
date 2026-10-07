<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Interface\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Interface\Cli\ArgvParser;
use Webreboot\GdeSlon\Interface\Cli\Option;
use Webreboot\GdeSlon\Interface\Cli\OptionValues;
use Webreboot\GdeSlon\Interface\Cli\UsageException;

final class ArgvParserTest extends TestCase
{
    public function testPositionalsAndOptions(): void
    {
        $input = ArgvParser::parse(['search', 'платье', 'красное', '--limit=5', '--format', 'json', '--yes'], self::options());

        self::assertSame(['search', 'платье', 'красное'], $input->positionals());
        self::assertSame('5', $input->value('limit'));
        self::assertSame('json', $input->value('format'));
        self::assertTrue($input->flag('yes'));
        self::assertFalse($input->flag('dry-run'));
        self::assertNull($input->value('page'));
        self::assertTrue($input->has('limit'));
        self::assertFalse($input->has('page'));
    }

    public function testShortFlagsAndGlobalsBeforeCommand(): void
    {
        $input = ArgvParser::parse(['--format=json', '-h', 'search', '-V'], self::options());

        self::assertSame(['search'], $input->positionals());
        self::assertTrue($input->flag('help'));
        self::assertTrue($input->flag('version'));
        self::assertSame('json', $input->value('format'));
    }

    public function testDoubleDashEndsOptions(): void
    {
        self::assertSame(['search', '-pink', '--limit=5'], ArgvParser::parse(['search', '--', '-pink', '--limit=5'], self::options())->positionals());
    }

    public function testLists(): void
    {
        $input = ArgvParser::parse(['--merchant=1,2', '--merchant', '3'], self::options());

        self::assertSame(['1', '2', '3'], $input->list('merchant'));
        self::assertSame([], ArgvParser::parse([], self::options())->list('merchant'));
    }

    /**
     * @param list<string> $argv
     */
    #[DataProvider('invalid')]
    public function testInvalid(array $argv, string $message): void
    {
        $this->expectException(UsageException::class);
        $this->expectExceptionMessage($message);

        ArgvParser::parse($argv, self::options());
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function invalid(): iterable
    {
        yield 'неизвестная опция' => [['--nosuch'], '--nosuch'];
        yield 'неизвестная короткая' => [['-x'], '-x'];
        yield 'минус-слово без --' => [['search', '-pink'], '--'];
        yield 'значение у флага' => [['--yes=1'], '--yes'];
        yield 'нет значения в конце' => [['--limit'], '--limit'];
        yield 'вместо значения опция' => [['--limit', '--yes'], '--limit'];
        yield 'вместо значения длинная опция со значением' => [['--limit', '--format=json'], '--limit'];
        yield 'пустое значение' => [['--limit='], '--limit'];
        yield 'повтор скалярной' => [['--limit=1', '--limit=2'], '--limit'];
        yield 'пустой элемент списка' => [['--merchant=1,,2'], '--merchant'];
        yield 'только запятая' => [['--merchant=,'], '--merchant'];
        yield 'аргумент не UTF-8' => [["\xff"], 'UTF-8'];
        yield 'значение не UTF-8' => [["--limit=\xff"], 'UTF-8'];
    }

    public function testOptionValues(): void
    {
        self::assertSame(1, OptionValues::positiveInt('1', 'limit'));
        self::assertSame(PHP_INT_MAX, OptionValues::positiveInt('9223372036854775807', 'limit'));
        foreach (['0', '-1', '05', '1e3', '', ' 1', '9223372036854775808', '1.5'] as $value) {
            try {
                OptionValues::positiveInt($value, 'limit');
                self::fail('Ожидалось исключение: ' . $value);
            } catch (UsageException $e) {
                self::assertStringContainsString('--limit', $e->getMessage());
            }
        }
        self::assertSame([1, 2], OptionValues::positiveInts(['1', '2'], 'merchant'));

        self::assertSame(14, OptionValues::choice('sale', ['discount' => 1, 'sale' => 14], 'kind'));
        try {
            OptionValues::choice('foo', ['price' => 'p', 'newest' => 'n'], 'sort');
            self::fail('Ожидалось исключение');
        } catch (UsageException $e) {
            self::assertStringContainsString('price, newest', $e->getMessage());
        }
    }

    /**
     * @return list<Option>
     */
    private static function options(): array
    {
        return [
            Option::value('format', 'формат'),
            Option::value('limit', 'лимит'),
            Option::value('page', 'страница'),
            Option::listOf('merchant', 'магазины'),
            Option::flag('yes', 'без вопроса'),
            Option::flag('dry-run', 'проверка'),
            Option::flag('help', 'справка', 'h'),
            Option::flag('version', 'версия', 'V'),
        ];
    }
}

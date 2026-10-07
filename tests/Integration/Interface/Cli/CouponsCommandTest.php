<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Integration\Interface\Cli;

use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Tests\Support\CliTester;
use Webreboot\GdeSlon\Tests\Support\FakeHttpTransport;
use Webreboot\GdeSlon\Tests\Support\Fixtures;
use Webreboot\GdeSlon\Tests\Support\FrozenClock;

/**
 * Ссылки купонов содержат токен XML API (в фикстуре — выдуманный 0a1b2c3d4e…): CLI по умолчанию их маскирует.
 */
final class CouponsCommandTest extends TestCase
{
    private const FIXTURE_TOKEN = '0a1b2c3d4e0a1b2c3d4e0a1b2c3d4e0a1b2c3d4e';
    private const ENV = ['GDESLON_API_TOKEN' => self::FIXTURE_TOKEN];

    public function testRequiresToken(): void
    {
        self::assertSame(3, (new CliTester())->run(['coupons']));
    }

    public function testLinksAreMaskedByDefault(): void
    {
        $cli = self::cli();
        self::assertSame(0, $cli->run(['coupons'], self::ENV));
        self::assertStringContainsString('PROMO10', $cli->stdout);
        self::assertStringNotContainsString('xf.gdeslon.ru', $cli->stdout, 'в таблице ссылок нет');
        self::assertStringNotContainsString(self::FIXTURE_TOKEN, $cli->stdout . $cli->stderr);

        $cli = self::cli();
        self::assertSame(0, $cli->run(['coupons', '--format=json'], self::ENV));
        self::assertStringContainsString('http://xf.gdeslon.ru/ck/***/336004?erid=2SDnjTEST001', $cli->stdout);
        self::assertStringNotContainsString(self::FIXTURE_TOKEN, $cli->stdout . $cli->stderr);
    }

    public function testRevealLinks(): void
    {
        $cli = self::cli();

        self::assertSame(0, $cli->run(['coupons', '--format=json', '--reveal-links'], self::ENV));
        self::assertStringContainsString('/ck/' . self::FIXTURE_TOKEN . '/336004', $cli->stdout);
        self::assertStringContainsString('токен', $cli->stderr);
        self::assertStringNotContainsString(self::FIXTURE_TOKEN, $cli->stderr);
    }

    public function testFilters(): void
    {
        $cli = self::cli();
        self::assertSame(0, $cli->run(['coupons', '--merchant=99157', '--kind=1,14'], self::ENV));
        self::assertSame('https://gdeslon.ru/api/coupons.xml?api_token=' . self::FIXTURE_TOKEN . '&merchant_id=99157&kind=1&kind=14', $cli->transport->lastRequest()->uri());

        // 441966 действует до 2026-10-31 — после этого остальные ещё действуют
        $cli = new CliTester((new FakeHttpTransport())->willReturn(200, Fixtures::read('coupons/coupons.xml')), FrozenClock::at('2026-11-15T10:00:00Z'));
        self::assertSame(0, $cli->run(['coupons', '--active', '--format=json'], self::ENV));
        $data = json_decode($cli->stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsArray($data['coupons']);
        self::assertCount(6, $data['coupons']);

        $cli = new CliTester((new FakeHttpTransport())->willReturn(400, Fixtures::read('coupons/error-400-merchant.xml')));
        self::assertSame(1, $cli->run(['coupons', '--merchant=23707'], self::ENV));
        self::assertStringContainsString('merchant_id:', $cli->stderr);
    }

    public function testShowAndKinds(): void
    {
        $cli = self::cli();
        self::assertSame(0, $cli->run(['coupons', 'show', '336004'], self::ENV));
        self::assertStringContainsString('erid 2SDnjTEST001', $cli->stdout);
        self::assertStringContainsString('/ck/***/336004', $cli->stdout);
        self::assertStringNotContainsString(self::FIXTURE_TOKEN, $cli->stdout);

        $cli = self::cli();
        self::assertSame(1, $cli->run(['coupons', 'show', '1'], self::ENV));
        self::assertStringContainsString('Купона 1 нет', $cli->stderr);

        $cli = self::cli();
        self::assertSame(0, $cli->run(['coupons', 'kinds'], self::ENV));
        self::assertStringContainsString('Black Friday', $cli->stdout);

        $cli = new CliTester((new FakeHttpTransport())->willReturn(200, Fixtures::read('coupons/coupons-empty.xml')));
        self::assertSame(0, $cli->run(['coupons'], self::ENV));
        self::assertStringContainsString('Ничего не найдено.', $cli->stderr);
    }

    public function testBrokenRecordsAreReported(): void
    {
        $cli = new CliTester((new FakeHttpTransport())->willReturn(200, Fixtures::read('coupons/coupons-broken.xml')));
        self::assertSame(0, $cli->run(['coupons'], self::ENV));
        self::assertStringContainsString('500001', $cli->stdout);
        self::assertStringContainsString('Пропущено записей с битыми данными: 10', $cli->stderr);
        self::assertSame(4, substr_count($cli->stderr, "\n"), 'итог и не больше трёх причин');

        $cli = new CliTester((new FakeHttpTransport())->willReturn(200, Fixtures::read('coupons/coupons-broken.xml')));
        self::assertSame(0, $cli->run(['coupons', '--format=json'], self::ENV));
        $data = json_decode($cli->stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsArray($data['skipped']);
        self::assertCount(10, $data['skipped']);
    }

    private static function cli(): CliTester
    {
        return new CliTester((new FakeHttpTransport())->willReturn(200, Fixtures::read('coupons/coupons.xml')));
    }
}

<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Integration\Interface\Cli;

use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Exception\TimeoutException;
use Webreboot\GdeSlon\Exception\TransportException;
use Webreboot\GdeSlon\Tests\Support\CliTester;
use Webreboot\GdeSlon\Tests\Support\FakeHttpTransport;
use Webreboot\GdeSlon\Tests\Support\Fixtures;

final class CatalogCommandsTest extends TestCase
{
    private const TOKEN = ['GDESLON_API_TOKEN' => 'secret-token-0123'];

    public function testCategoriesTable(): void
    {
        $cli = self::cli(200, Fixtures::read('categories/categories.json'));

        self::assertSame(0, $cli->run(['categories', '--no-cache']));
        self::assertStringContainsString('Подарки, сувениры, цветы', $cli->stdout);
        self::assertStringContainsString('  Украшения и бижутерия', $cli->stdout, 'дочерние — с отступом');
        self::assertSame('https://api.gdeslon.ru/gdeslon-categories.json', $cli->transport->lastRequest()->url());
        self::assertSame('', $cli->stderr);
    }

    public function testCategoriesJsonAndSubtree(): void
    {
        $cli = self::cli(200, Fixtures::read('categories/categories.json'));
        self::assertSame(0, $cli->run(['categories', '1', '--depth=1', '--format=json', '--no-cache']));
        $data = self::json($cli->stdout);
        self::assertSame([['id' => 1, 'name' => 'Подарки, сувениры, цветы']], $data['breadcrumbs']);
        self::assertIsArray($data['categories']);
        self::assertGreaterThan(1, count($data['categories']), 'категория и её дети');
        foreach ($data['categories'] as $category) {
            self::assertIsArray($category);
            self::assertContains($category['depth'], [1, 2], 'не глубже --depth от выбранной');
        }

        $cli = self::cli(200, Fixtures::read('categories/categories.json'));
        self::assertSame(1, $cli->run(['categories', '999999', '--no-cache']));
        self::assertStringContainsString('Категории 999999 нет', $cli->stderr);
        self::assertSame('', $cli->stdout);
    }

    public function testApiTextCannotControlTerminal(): void
    {
        $json = str_replace('"Подарки, сувениры, цветы"', '"Подарки\u001b]0;pwned\u0007\u001b[2J"', Fixtures::read('categories/categories.json'));
        self::assertStringContainsString('pwned', $json, 'фикстура подменена');
        $cli = self::cli(200, $json);

        self::assertSame(0, $cli->run(['categories', '1', '--no-cache']));
        self::assertStringContainsString('Путь: Подарки', $cli->stdout);
        self::assertStringNotContainsString("\e", $cli->stdout);
        self::assertStringNotContainsString("\x07", $cli->stdout);
    }

    public function testCategoriesErrors(): void
    {
        foreach ([[500, 'Something broken!'], [200, '<html>не JSON</html>']] as [$status, $body]) {
            $cli = self::cli($status, $body);
            self::assertSame(1, $cli->run(['categories', '--no-cache']));
            self::assertSame('', $cli->stdout);
            self::assertStringStartsWith('Ошибка: ', $cli->stderr);
        }

        $cli = new CliTester((new FakeHttpTransport())->willThrow(new TimeoutException('превышено время ожидания', 'GET', 'https://api.gdeslon.ru/', 28)));
        self::assertSame(1, $cli->run(['categories', '--no-cache']));
        self::assertStringContainsString('превышено время ожидания', $cli->stderr);

        $cli = new CliTester((new FakeHttpTransport())->willThrow(new TransportException('соединение сброшено', 'GET', 'https://api.gdeslon.ru/', 56)));
        self::assertSame(1, $cli->run(['categories', '--no-cache']));
        self::assertStringStartsWith('Ошибка: ', $cli->stderr);
        self::assertStringContainsString('соединение сброшено', $cli->stderr);
    }

    public function testMerchants(): void
    {
        $public = self::cli(200, Fixtures::read('merchants/shops-public.xml'));
        self::assertSame(0, $public->run(['merchants', '--no-cache']));
        self::assertArrayNotHasKey('api_token', $public->transport->lastRequest()->query(), 'без токена — публичный каталог');

        $cli = self::cli(200, Fixtures::read('merchants/shops.xml'));
        self::assertSame(0, $cli->run(['merchants', 'list', '--search=KOMUS', '--no-cache'], self::TOKEN));
        self::assertSame('secret-token-0123', $cli->transport->lastRequest()->query()['api_token'] ?? null);
        self::assertStringContainsString('komus.ru', $cli->stdout);
        self::assertStringNotContainsString('superstep', $cli->stdout);
        self::assertStringNotContainsString('secret-token-0123', $cli->stdout . $cli->stderr);

        $cli = self::cli(200, Fixtures::read('merchants/shops.xml'));
        self::assertSame(0, $cli->run(['merchants', '--domain=https://www.komus.ru/x', '--format=json', '--no-cache'], self::TOKEN));
        $data = self::json($cli->stdout);
        self::assertIsArray($data['merchants']);
        self::assertCount(1, $data['merchants']);

        $cli = self::cli(200, Fixtures::read('merchants/shops.xml'));
        self::assertSame(0, $cli->run(['merchants', '--category=5', '--search=nosuchshop', '--no-cache'], self::TOKEN));
        self::assertStringContainsString('Ничего не найдено.', $cli->stderr);
    }

    public function testMerchantRejectedToken(): void
    {
        $cli = self::cli(200, Fixtures::read('merchants/shops-public.xml'));

        self::assertSame(3, $cli->run(['merchants', '--no-cache'], self::TOKEN), 'токен задан, а ссылок нет — токен не принят');
    }

    public function testMerchantShowAndCategories(): void
    {
        $cli = self::cli(200, Fixtures::read('merchants/shops.xml'));
        self::assertSame(0, $cli->run(['merchants', 'show', '105263', '--no-cache'], self::TOKEN), $cli->stderr);
        self::assertStringContainsString('superstep.ru', $cli->stdout);
        self::assertStringContainsString('Партнёрская ссылка', $cli->stdout);

        $cli = self::cli(200, Fixtures::read('merchants/shops.xml'));
        self::assertSame(1, $cli->run(['merchants', 'show', '1', '--no-cache'], self::TOKEN));
        self::assertStringContainsString('Магазина 1 нет', $cli->stderr);

        self::assertSame(2, (new CliTester())->run(['merchants', 'show', 'abc']));
        self::assertSame(2, (new CliTester())->run(['merchants', 'show']));

        $cli = self::cli(200, Fixtures::read('merchants/shops.xml'));
        self::assertSame(0, $cli->run(['merchants', 'categories', '--format=json', '--no-cache'], self::TOKEN));
        $data = self::json($cli->stdout);
        self::assertIsArray($data['categories']);
        self::assertNotEmpty($data['categories']);
    }

    public function testSearchRequiresToken(): void
    {
        $cli = new CliTester();

        self::assertSame(3, $cli->run(['search', 'платье']));
        self::assertStringContainsString('GDESLON_API_TOKEN', $cli->stderr);
        self::assertStringContainsString('https://gdeslon.ru/api_settings/xml', $cli->stderr);
        self::assertSame(0, $cli->factoryCalls);
    }

    public function testSearchCriteriaReachRequest(): void
    {
        $cli = self::cli(200, Fixtures::read('search/search.xml'));

        self::assertSame(0, $cli->run(['search', 'платье', 'красное', '--merchant=107054,111211', '--exclude-category=26', '--limit=8', '--page=2',
            '--sort=partner-benefit'], self::TOKEN));

        $query = $cli->transport->lastRequest()->query();
        self::assertSame('платье красное', $query['q'] ?? null);
        self::assertSame('107054,111211', $query['m'] ?? null);
        self::assertSame('26', $query['no_tid'] ?? null);
        self::assertSame(8, $query['l'] ?? null);
        self::assertSame(2, $query['p'] ?? null);
        self::assertSame('partner_benefit', $query['order'] ?? null);
        self::assertStringContainsString('Страница 2', $cli->stdout);
        self::assertStringContainsString('--page=3', $cli->stdout);
        self::assertStringNotContainsString('secret-token-0123', $cli->stdout . $cli->stderr);
    }

    public function testSearchJsonAndEmpty(): void
    {
        $cli = self::cli(200, Fixtures::read('search/search.xml'));
        self::assertSame(0, $cli->run(['search', '--', '-pink', '--format=json'], self::TOKEN));
        self::assertSame('-pink --format=json', $cli->transport->lastRequest()->query()['q'] ?? null, 'после «--» всё — запрос');

        $cli = self::cli(200, Fixtures::read('search/search.xml'));
        self::assertSame(0, $cli->run(['--format=json', 'search', 'платье', '--limit=8'], self::TOKEN));
        $data = self::json($cli->stdout);
        self::assertSame(2, $data['next_page']);
        self::assertIsArray($data['offers']);
        self::assertCount(8, $data['offers']);

        $cli = self::cli(200, Fixtures::read('search/search-empty.xml'));
        self::assertSame(0, $cli->run(['search', 'nothing'], self::TOKEN));
        self::assertStringContainsString('Ничего не найдено.', $cli->stderr);
    }

    public function testSearchInvalidCriteria(): void
    {
        foreach ([['--limit=101'], ['--limit=0'], ['--page=0'], ['--page=1001', '--limit=10'], ['--sort=foo'], ['--merchant=0'], ['-pink']] as $options) {
            $cli = new CliTester();
            self::assertSame(2, $cli->run(['search', 'x', ...$options], self::TOKEN), implode(' ', $options));
            self::assertSame([], $cli->transport->requests());
        }
    }

    public function testSearchRejected(): void
    {
        $cli = self::cli(403, 'This affiliate token does not exists');
        self::assertSame(3, $cli->run(['search', 'x'], self::TOKEN));

        $cli = self::cli(404, "There have been validation errors: [ { param: '_gs_at', msg: 'Invalid value', value: 'secret-token-0123' } ]");
        self::assertSame(3, $cli->run(['search', 'x'], self::TOKEN));
        self::assertStringNotContainsString('secret-token-0123', $cli->stdout . $cli->stderr);
    }

    private static function cli(int $status, string $body): CliTester
    {
        return new CliTester((new FakeHttpTransport())->willReturn($status, $body));
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(string $text): array
    {
        $data = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }
}

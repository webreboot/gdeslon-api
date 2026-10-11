<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Integration\Interface\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Catalog\CategoryTree;
use Webreboot\GdeSlon\Domain\Shared\DataOrigin;
use Webreboot\GdeSlon\Exception\TimeoutException;
use Webreboot\GdeSlon\Exception\TransportException;
use Webreboot\GdeSlon\GdeSlon;
use Webreboot\GdeSlon\Infrastructure\Api\ApiClient;
use Webreboot\GdeSlon\Infrastructure\Api\Catalog\CategoryMapper;
use Webreboot\GdeSlon\Infrastructure\Api\Catalog\HttpProductCatalog;
use Webreboot\GdeSlon\Infrastructure\Api\Catalog\MerchantXmlParser;
use Webreboot\GdeSlon\Infrastructure\Http\HttpRequest;
use Webreboot\GdeSlon\Testing\FakeCategoryRepository;
use Webreboot\GdeSlon\Testing\FakeHttpTransport;
use Webreboot\GdeSlon\Testing\FakeMerchantRepository;
use Webreboot\GdeSlon\Tests\Support\CliTester;
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

    /**
     * @param list<string>          $argv
     * @param array<string, string> $env
     */
    #[DataProvider('merchantCommands')]
    public function testMerchantCommandsChoosePublicModeByToken(array $argv, array $env, bool $public): void
    {
        $repository = new FakeMerchantRepository((new MerchantXmlParser())->parse(Fixtures::read('merchants/shops.xml')));
        $cli = new CliTester(build: static fn (): GdeSlon => new GdeSlon(new FakeHttpTransport(), null, $repository));

        self::assertSame(0, $cli->run($argv, $env), $cli->stderr);
        self::assertSame(1, $repository->calls());
        self::assertSame($public, $repository->lastPublic());
    }

    /**
     * @return iterable<string, array{list<string>, array<string, string>, bool}>
     */
    public static function merchantCommands(): iterable
    {
        foreach (['merchants' => ['merchants'], 'merchants show' => ['merchants', 'show', '105263'], 'merchants categories' => ['merchants', 'categories']] as $name => $argv) {
            yield $name . ' без токена' => [$argv, [], true];
            yield $name . ' с токеном' => [$argv, self::TOKEN, false];
        }
    }

    public function testMerchantShowAndCategoriesWithoutToken(): void
    {
        $cli = self::cli(200, Fixtures::read('merchants/shops-public.xml'));
        self::assertSame(0, $cli->run(['merchants', 'show', '105263', '--no-cache']), $cli->stderr);
        self::assertStringContainsString('superstep.ru', $cli->stdout);
        self::assertStringContainsString('нет (задайте GDESLON_API_TOKEN)', $cli->stdout);
        self::assertArrayNotHasKey('api_token', $cli->transport->lastRequest()->query());

        $cli = self::cli(200, Fixtures::read('merchants/shops-public.xml'));
        self::assertSame(0, $cli->run(['merchants', 'categories', '--format=json', '--no-cache']), $cli->stderr);
        $data = self::json($cli->stdout);
        self::assertIsArray($data['categories']);
        self::assertNotEmpty($data['categories']);
        self::assertArrayNotHasKey('api_token', $cli->transport->lastRequest()->query());
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
        self::assertStringNotContainsString('частями', $cli->stdout);
        self::assertStringNotContainsString('secret-token-0123', $cli->stdout . $cli->stderr);
    }

    public function testSearchDegraded(): void
    {
        $cli = self::degradedCli();
        self::assertSame(0, $cli->run(['search', 'платье'], self::TOKEN));
        self::assertCount(3, $cli->transport->requests());
        self::assertStringContainsString("Страница 1 · найдено 8140 · загружено частями по 5 · следующая: --page=2
", $cli->stdout);
        self::assertSame('', $cli->stderr);

        $cli = self::degradedCli();
        self::assertSame(0, $cli->run(['search', 'платье', '--format=json'], self::TOKEN));
        $data = self::json($cli->stdout);
        self::assertSame(10, $data['limit']);
        self::assertSame(5, $data['effective_limit']);
        self::assertTrue($data['degraded']);
        self::assertIsArray($data['offers']);
        self::assertCount(10, $data['offers']);
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
        self::assertCount(7, $data['offers']);
        self::assertIsArray($data['skipped']);
        self::assertCount(1, $data['skipped']);
        self::assertIsArray($data['offers'][0]);
        self::assertSame(['amount' => '100', 'currency' => 'RUB'], $data['offers'][0]['price']);
        self::assertIsArray($data['offers'][2]);
        self::assertNull($data['offers'][2]['charge']);
        self::assertStringContainsString('Пропущено записей: 1', $cli->stderr);
        self::assertStringContainsString('цена 0', $cli->stderr);

        $cli = self::cli(200, Fixtures::read('search/search-empty.xml'));
        self::assertSame(0, $cli->run(['search', 'nothing'], self::TOKEN));
        self::assertStringContainsString('Ничего не найдено.', $cli->stderr);
    }

    public function testSearchInvalidCriteria(): void
    {
        foreach ([['--limit=101'], ['--limit=0'], ['--page=0'], ['--page=1001', '--limit=10'], ['--sort=foo'], ['--merchant=0'], ['-pink'],
            ['--limit=abc', '--category=1113', '--subcategories'], ['--limit=101', '--category=1113', '--subcategories'], ['--sort=foo', '--category=1113', '--subcategories'],
            ['--page=1001', '--limit=10', '--category=1113', '--subcategories'], ['--parked-domain=foo', '--category=1113', '--subcategories'],
            ['--merchant=5', '--exclude-merchant=5', '--category=1113', '--subcategories']] as $options) {
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

    public function testSearchWithSubcategoriesExpandsIncludedAndExcluded(): void
    {
        $cli = self::categoriesThenSearch();

        self::assertSame(0, $cli->run(['search', 'тренч', '--category=1113', '--exclude-category=1216', '--subcategories', '--no-cache'], self::TOKEN), $cli->stderr);

        $requests = $cli->transport->requests();
        self::assertCount(2, $requests);
        self::assertSame('https://api.gdeslon.ru/gdeslon-categories.json', $requests[0]->url());
        $query = $requests[1]->query();
        self::assertSame('1113,1114,1128,1522', $query['tid'] ?? null);
        self::assertArrayNotHasKey('no_tid', $query, 'исключённые уже вычтены из tid');
        self::assertSame('тренч', $query['q'] ?? null);
        self::assertStringContainsString('Страница 1', $cli->stdout);
    }

    public function testSearchWithSubcategoriesExclusionWins(): void
    {
        $cli = self::categoriesThenSearch();

        self::assertSame(0, $cli->run(['search', '--category=1216,1231', '--exclude-category=1230,1231', '--subcategories', '--no-cache'], self::TOKEN), $cli->stderr);

        $query = $cli->transport->lastRequest()->query();
        self::assertSame('1216,1217', $query['tid'] ?? null);
        self::assertArrayNotHasKey('no_tid', $query);
    }

    public function testSearchWithSubcategoriesOnlyExcluded(): void
    {
        $cli = self::categoriesThenSearch();

        self::assertSame(0, $cli->run(['search', '--exclude-category=1230', '--subcategories', '--no-cache'], self::TOKEN), $cli->stderr);

        $query = $cli->transport->lastRequest()->query();
        self::assertArrayNotHasKey('tid', $query);
        self::assertSame('1230,1231,1232', $query['no_tid'] ?? null);
    }

    public function testSearchWithSubcategoriesWhenEverythingIsExcluded(): void
    {
        $cli = self::cli(200, Fixtures::read('categories/categories.json'));

        self::assertSame(2, $cli->run(['search', '--category=1216', '--exclude-category=1113', '--subcategories', '--no-cache'], self::TOKEN));
        self::assertStringContainsString('исключены', $cli->stderr);
        self::assertSame('', $cli->stdout);
        self::assertCount(1, $cli->transport->requests());
    }

    public function testSearchWithSubcategoriesUnknownCategory(): void
    {
        foreach ([999999 => ['--category=1113,999999'], 277 => ['--category=1113', '--exclude-category=277']] as $missing => $options) {
            $cli = self::cli(200, Fixtures::read('categories/categories.json'));

            self::assertSame(1, $cli->run(['search', ...$options, '--subcategories', '--no-cache'], self::TOKEN));
            self::assertSame(sprintf("Категории %d нет\n", $missing), $cli->stderr);
            self::assertSame('', $cli->stdout);
            self::assertCount(1, $cli->transport->requests(), 'поиск не идёт');
        }
    }

    public function testSearchWithSubcategoriesWithoutCategoriesDoesNotLoadThem(): void
    {
        $cli = self::cli(200, Fixtures::read('search/search.xml'));

        self::assertSame(0, $cli->run(['search', 'тренч', '--merchant=107054', '--subcategories'], self::TOKEN), $cli->stderr);
        self::assertCount(1, $cli->transport->requests());
        self::assertSame('https://api.gdeslon.ru/api/search.xml', $cli->transport->lastRequest()->url());
        self::assertArrayNotHasKey('tid', $cli->transport->lastRequest()->query());
    }

    public function testSearchWithoutSubcategoriesKeepsCategoriesAsIs(): void
    {
        $cli = self::cli(200, Fixtures::read('search/search.xml'));

        self::assertSame(0, $cli->run(['search', '--category=1113', '--exclude-category=1216'], self::TOKEN), $cli->stderr);
        self::assertCount(1, $cli->transport->requests());
        self::assertSame('1113', $cli->transport->lastRequest()->query()['tid'] ?? null);
        self::assertSame('1216', $cli->transport->lastRequest()->query()['no_tid'] ?? null);
    }

    public function testSearchWithSubcategoriesCategoriesFailure(): void
    {
        $cli = self::cli(500, 'Something broken!');

        self::assertSame(1, $cli->run(['search', '--category=1113', '--subcategories', '--no-cache'], self::TOKEN));
        self::assertStringStartsWith('Ошибка: ', $cli->stderr);
        self::assertCount(1, $cli->transport->requests());
        self::assertSame('https://api.gdeslon.ru/gdeslon-categories.json', $cli->transport->lastRequest()->url());
    }

    public function testSearchSubcategoriesIsFlagInHelp(): void
    {
        $cli = new CliTester();
        self::assertSame(0, $cli->run(['search', '--help']));
        self::assertStringContainsString('--subcategories', $cli->stdout);

        $cli = new CliTester();
        self::assertSame(2, $cli->run(['search', '--category=1113', '--subcategories=yes'], self::TOKEN));
        self::assertSame([], $cli->transport->requests());
    }

    public function testPageOfZeroPricesShowsNextPage(): void
    {
        $offer = '<offer merchant_id="1" id="%d"><price>0</price><currencyId>RUR</currencyId><name><![CDATA[x]]></name>'
            . '<url>https://af.gdeslon.ru/cm/0a1b2c3d4e/?mid=1</url></offer>';
        $xml = '<?xml version="1.0" encoding="UTF-8"?><!DOCTYPE yml_catalog SYSTEM "shops.dtd"><yml_catalog date="2026-10-11 10:09:18">'
            . '<info><documents_number>82</documents_number></info><offers>' . sprintf($offer, 1) . sprintf($offer, 2) . '</offers></yml_catalog>';

        $cli = self::cli(200, $xml);
        self::assertSame(0, $cli->run(['search', 'курс', '--limit=2'], self::TOKEN), $cli->stderr);

        self::assertStringContainsString('--page=2', $cli->stdout);
        self::assertStringContainsString('найдено 82', $cli->stdout);
        self::assertStringNotContainsString('Ничего не найдено.', $cli->stderr);
        self::assertStringContainsString('Пропущено записей: 2', $cli->stderr);
    }

    public function testCategoriesRefreshFlagReachesFacade(): void
    {
        $repository = new FakeCategoryRepository(self::categoryTree());
        $cli = new CliTester(build: static fn (): GdeSlon => new GdeSlon(new FakeHttpTransport(), $repository));

        self::assertSame(0, $cli->run(['categories', '--refresh']), $cli->stderr);
        self::assertTrue($repository->lastRefresh());
        self::assertSame(0, $cli->run(['categories', '1']), $cli->stderr);
        self::assertFalse($repository->lastRefresh());

        $help = new CliTester();
        self::assertSame(0, $help->run(['categories', '--help']));
        self::assertStringContainsString('--refresh', $help->stdout);
    }

    /**
     * @param list<string>          $argv
     * @param array<string, string> $env
     */
    #[DataProvider('merchantRefreshCommands')]
    public function testMerchantsListRefreshFlagReachesFacade(array $argv, array $env, bool $public, bool $refresh): void
    {
        $repository = new FakeMerchantRepository((new MerchantXmlParser())->parse(Fixtures::read('merchants/shops.xml')));
        $cli = new CliTester(build: static fn (): GdeSlon => new GdeSlon(new FakeHttpTransport(), null, $repository));

        self::assertSame(0, $cli->run($argv, $env), $cli->stderr);
        self::assertSame($public, $repository->lastPublic());
        self::assertSame($refresh, $repository->lastRefresh());
    }

    /**
     * @return iterable<string, array{list<string>, array<string, string>, bool, bool}>
     */
    public static function merchantRefreshCommands(): iterable
    {
        yield 'merchants --refresh с токеном' => [['merchants', '--refresh'], self::TOKEN, false, true];
        yield 'merchants list --refresh без токена' => [['merchants', 'list', '--refresh'], [], true, true];
        yield 'merchants list без --refresh' => [['merchants', 'list'], self::TOKEN, false, false];
        yield 'merchants show' => [['merchants', 'show', '105263'], self::TOKEN, false, false];
        yield 'merchants categories' => [['merchants', 'categories'], self::TOKEN, false, false];
    }

    /**
     * @param list<string>          $argv
     * @param array<string, string> $env
     */
    #[DataProvider('staleCatalogCommands')]
    public function testStaleCatalogWarnsInStderrAndKeepsStdout(array $argv, array $env): void
    {
        $current = self::catalogCli(DataOrigin::Revalidated);
        self::assertSame(0, $current->run($argv, $env), $current->stderr);
        self::assertStringNotContainsString('сохранённой копии', $current->stderr);

        $stale = self::catalogCli(DataOrigin::Stale);
        self::assertSame(0, $stale->run($argv, $env), $stale->stderr);
        self::assertStringContainsString('сохранённой копии', $stale->stderr);
        self::assertSame($current->stdout, $stale->stdout);
        self::assertNotSame('', $stale->stdout);
    }

    /**
     * @return iterable<string, array{list<string>, array<string, string>}>
     */
    public static function staleCatalogCommands(): iterable
    {
        yield 'categories' => [['categories'], []];
        yield 'categories --format=json' => [['categories', '1', '--format=json'], []];
        yield 'merchants' => [['merchants'], self::TOKEN];
        yield 'merchants --format=json' => [['merchants', '--format=json'], self::TOKEN];
        yield 'merchants show' => [['merchants', 'show', '105263'], self::TOKEN];
        yield 'merchants categories' => [['merchants', 'categories'], []];
        yield 'search --subcategories' => [['search', 'тренч', '--category=1113', '--subcategories'], self::TOKEN];
    }

    public function testRefreshFailureExitsWithErrorAndKeepsCopy(): void
    {
        $dir = sys_get_temp_dir() . '/gdeslon-cli-refresh-' . bin2hex(random_bytes(4));
        $cli = new CliTester((new FakeHttpTransport())
            ->willReturn(200, Fixtures::read('categories/categories.json'), ['ETag' => ['"e"']])
            ->willReturn(200, Fixtures::read('merchants/shops.xml'), ['ETag' => ['W/"e"']])
            ->willFail(28, 'Operation timed out')
            ->willReturn(401, '')
            ->willReturn(200, Fixtures::read('merchants/shops-public.xml')));
        try {
            self::assertSame(0, $cli->run(['categories', '--cache-dir=' . $dir]), $cli->stderr);
            self::assertSame(0, $cli->run(['merchants', '--cache-dir=' . $dir], self::TOKEN), $cli->stderr);
            $before = self::files($dir);
            self::assertCount(2, $before);

            self::assertSame(1, $cli->run(['categories', '--refresh', '--cache-dir=' . $dir]));
            self::assertStringStartsWith('Ошибка: ', $cli->stderr);
            self::assertSame('', $cli->stdout);
            self::assertSame('"e"', $cli->transport->lastRequest()->header('If-None-Match'));

            self::assertSame(3, $cli->run(['merchants', '--refresh', '--cache-dir=' . $dir], self::TOKEN));
            self::assertSame(3, $cli->run(['merchants', 'list', '--refresh', '--cache-dir=' . $dir], self::TOKEN));
            self::assertStringNotContainsString('secret-token-0123', $cli->stderr);
            self::assertSame($before, self::files($dir));

            self::assertSame(0, $cli->run(['categories', '--cache-dir=' . $dir]), 'обычный вызов — из копии');
            self::assertCount(5, $cli->transport->requests());
        } finally {
            foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
    }

    public function testRefreshIsUnknownForOtherCommands(): void
    {
        foreach ([['merchants', 'show', '105263', '--refresh'], ['merchants', 'categories', '--refresh'], ['search', 'x', '--refresh'], ['coupons', '--refresh'], ['--refresh', 'orders']] as $argv) {
            $cli = new CliTester();
            self::assertSame(2, $cli->run($argv, self::TOKEN), implode(' ', $argv));
            self::assertStringContainsString('--refresh', $cli->stderr, implode(' ', $argv));
            self::assertSame(0, $cli->factoryCalls, implode(' ', $argv));
        }
    }

    private static function catalogCli(DataOrigin $origin): CliTester
    {
        $categories = new FakeCategoryRepository(self::categoryTree()->withOrigin($origin));
        $merchants = new FakeMerchantRepository((new MerchantXmlParser())->parse(Fixtures::read('merchants/shops.xml'))->withOrigin($origin));
        $transport = (new FakeHttpTransport())->willReturn(200, Fixtures::read('search/search.xml'));
        $products = new HttpProductCatalog(new ApiClient($transport), 'secret-token-0123');

        return new CliTester(build: static fn (): GdeSlon => new GdeSlon($transport, $categories, $merchants, $products));
    }

    private static function categoryTree(): CategoryTree
    {
        return (new CategoryMapper())->toTree(Fixtures::json('categories/categories.json'));
    }

    /**
     * @return array<string, string>
     */
    private static function files(string $dir): array
    {
        $files = [];
        foreach (glob($dir . '/*') ?: [] as $file) {
            $files[basename($file)] = (string) file_get_contents($file);
        }

        return $files;
    }

    private static function cli(int $status, string $body): CliTester
    {
        return new CliTester((new FakeHttpTransport())->willReturn($status, $body));
    }

    public function testSearchDegradedPartSizeIsDivisorOfLimit(): void
    {
        $xml = Fixtures::read('search/search-l5-p1.xml');
        $fourOffers = substr($xml, 0, (int) strrpos($xml, '<offer ')) . substr($xml, (int) strpos($xml, '</offers>'));
        $cli = new CliTester((new FakeHttpTransport())
            ->willFail(28, 'Operation timed out')
            ->willReturn(200, $fourOffers)
            ->willReturn(200, $fourOffers)
            ->willReturn(200, $fourOffers));

        self::assertSame(0, $cli->run(['search', 'платье', '--limit=12'], self::TOKEN));

        self::assertSame([12, 4, 4, 4], array_map(static fn (HttpRequest $r): mixed => $r->query()['l'] ?? null, $cli->transport->requests()));
        self::assertSame([1, 1, 2, 3], array_map(static fn (HttpRequest $r): mixed => $r->query()['p'] ?? null, $cli->transport->requests()));
        self::assertStringContainsString("Страница 1 · найдено 8140 · загружено частями по 4 · следующая: --page=2\n", $cli->stdout);
    }

    private static function degradedCli(): CliTester
    {
        return new CliTester((new FakeHttpTransport())
            ->willFail(28, 'Operation timed out')
            ->willReturn(200, Fixtures::read('search/search-l5-p1.xml'))
            ->willReturn(200, Fixtures::read('search/search-l5-p2.xml')));
    }

    private static function categoriesThenSearch(): CliTester
    {
        return new CliTester((new FakeHttpTransport())
            ->willReturn(200, Fixtures::read('categories/categories.json'))
            ->willReturn(200, Fixtures::read('search/search.xml')));
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

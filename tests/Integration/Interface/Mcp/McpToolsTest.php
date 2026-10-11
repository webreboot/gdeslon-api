<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Integration\Interface\Mcp;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Exception\TimeoutException;
use Webreboot\GdeSlon\GdeSlon;
use Webreboot\GdeSlon\Infrastructure\Api\Catalog\CategoryMapper;
use Webreboot\GdeSlon\Infrastructure\Api\Catalog\MerchantXmlParser;
use Webreboot\GdeSlon\Interface\Normalizer\JsonShapes;
use Webreboot\GdeSlon\Tests\Support\FakeHttpTransport;
use Webreboot\GdeSlon\Tests\Support\FakeMerchantRepository;
use Webreboot\GdeSlon\Tests\Support\Fixtures;
use Webreboot\GdeSlon\Tests\Support\FrozenClock;
use Webreboot\GdeSlon\Tests\Support\McpTester;

final class McpToolsTest extends TestCase
{
    private const TOKEN = ['GDESLON_API_TOKEN' => 'secret-token-0123'];
    private const SALES = ['GDESLON_USER_ID' => '1234', 'GDESLON_API_KEY' => 'test-api-key'];
    private const COUPON_TOKEN = '0a1b2c3d4e0a1b2c3d4e0a1b2c3d4e0a1b2c3d4e';

    private const PAGE = ['total' => 'int', 'limit' => 'int', 'offset' => 'int', 'next_offset' => 'int|null'];

    public function testCategoriesRootsAndSubtree(): void
    {
        $mcp = self::mcp(200, Fixtures::read('categories/categories.json'));
        $data = McpTester::structured($mcp->call('get_categories'));
        self::assertShape(['categories' => ['[]' => JsonShapes::CATEGORY], 'breadcrumbs' => ['[]' => ['id' => 'int', 'name' => 'string']]] + self::PAGE, $data);
        self::assertSame('https://api.gdeslon.ru/gdeslon-categories.json', $mcp->cli->transport->lastRequest()->url());
        self::assertSame([], $data['breadcrumbs']);
        $tree = (new CategoryMapper())->toTree(Fixtures::json('categories/categories.json'));
        $roots = count($tree->roots());
        self::assertGreaterThan(0, $roots);
        self::assertGreaterThan(0, count($tree->orphans()), 'в фикстуре есть сироты');
        self::assertSame($roots + count($tree->orphans()), $data['total'], 'корни и сироты, без подкатегорий');
        self::assertLessThan($tree->count(), $data['total']);
        $categories = (array) $data['categories'];
        self::assertCount($roots, array_filter($categories, static fn (mixed $c): bool => is_array($c) && $c['parent_id'] === null));
        $ids = array_column($categories, 'id');
        foreach ($categories as $category) {
            self::assertIsArray($category);
            self::assertNotContains($category['parent_id'], $ids, 'без подкатегорий: родителя нет в ответе');
        }

        $data = McpTester::structured(self::mcp(200, Fixtures::read('categories/categories.json'))->call('get_categories', ['category_id' => 1, 'depth' => 1]));
        self::assertSame([['id' => 1, 'name' => 'Подарки, сувениры, цветы']], $data['breadcrumbs']);
        $depths = array_column((array) $data['categories'], 'depth');
        self::assertSame(1, $depths[0]);
        self::assertGreaterThan(1, count($depths));
        self::assertSame([1, 2], array_values(array_unique($depths)));
    }

    public function testCategoriesSearchAndErrors(): void
    {
        $data = McpTester::structured(self::mcp(200, Fixtures::read('categories/categories.json'))->call('get_categories', ['name_contains' => 'ОДЕЖД', 'limit' => 500]));
        $names = array_column((array) $data['categories'], 'name');
        self::assertContains('Женская одежда', $names, 'кириллица без учёта регистра');
        self::assertContains('Одежда', $names);

        self::assertSame('[not_found] Категории 999999 нет', McpTester::failure(self::mcp(200, Fixtures::read('categories/categories.json'))->call('get_categories', ['category_id' => 999999])));

        $mcp = new McpTester();
        self::assertStringStartsWith('[invalid_arguments] ', McpTester::failure($mcp->call('get_categories', ['category_id' => 1, 'name_contains' => 'x'])));
        self::assertSame([], $mcp->cli->transport->requests());

        self::assertStringStartsWith('[failed] ', McpTester::failure(self::mcp(500, 'Something broken!')->call('get_categories')));
        self::assertStringStartsWith('[failed] ', McpTester::failure(self::mcp(200, '<html>')->call('get_categories')));
        $timeout = new McpTester((new FakeHttpTransport())->willThrow(new TimeoutException('превышено время ожидания', 'GET', 'https://api.gdeslon.ru/', 28)));
        self::assertStringContainsString('превышено время ожидания', McpTester::failure($timeout->call('get_categories')));
    }

    public function testMerchants(): void
    {
        $mcp = self::mcp(200, Fixtures::read('merchants/shops-public.xml'));
        $data = McpTester::structured($mcp->call('list_merchants'));
        self::assertArrayNotHasKey('api_token', $mcp->cli->transport->lastRequest()->query());
        self::assertShape(['merchants' => ['[]' => JsonShapes::MERCHANT_SUMMARY], 'skipped' => ['[]' => 'string']] + self::PAGE, $data);

        $mcp = self::mcp(200, Fixtures::read('merchants/shops.xml'));
        $data = McpTester::structured($mcp->call('list_merchants', ['search' => 'KOMUS'], self::TOKEN));
        self::assertSame('secret-token-0123', $mcp->cli->transport->lastRequest()->query()['api_token'] ?? null);
        self::assertSame(['komus.ru'], array_column((array) $data['merchants'], 'domain'));
        self::assertStringNotContainsString('secret-token-0123', $mcp->cli->stdout . $mcp->cli->stderr);

        $data = McpTester::structured(self::mcp(200, Fixtures::read('merchants/shops.xml'))->call('list_merchants', ['domain' => 'https://www.komus.ru/x', 'search' => 'nosuchshop'], self::TOKEN));
        self::assertSame(['merchants' => [], 'skipped' => [], 'total' => 0, 'limit' => 20, 'offset' => 0, 'next_offset' => null], $data);

        $data = McpTester::structured(self::mcp(200, Fixtures::read('merchants/shops.xml'))->call('list_merchants', ['limit' => 2, 'offset' => 2], self::TOKEN));
        self::assertCount(2, (array) $data['merchants']);
        self::assertSame(4, $data['next_offset']);
        self::assertSame(9, $data['total']);

        self::assertStringStartsWith('[access] ', McpTester::failure(self::mcp(200, Fixtures::read('merchants/shops-public.xml'))->call('list_merchants', [], self::TOKEN)), 'токен не принят');
    }

    public function testMerchantCardAndCategories(): void
    {
        $data = McpTester::structured(self::mcp(200, Fixtures::read('merchants/shops.xml'))->call('get_merchant', ['merchant_id' => 105263], self::TOKEN));
        self::assertShape(['merchant' => JsonShapes::MERCHANT], $data);
        self::assertIsArray($data['merchant']);
        self::assertSame('Kra23dyAk', $data['merchant']['erid']);
        $data = McpTester::structured(self::mcp(200, Fixtures::read('merchants/shops.xml'))->call('get_merchant', ['merchant_id' => 101124], self::TOKEN));
        self::assertIsArray($data['merchant']);
        self::assertSame(['affiliate_link' => null, 'erid' => 'Kra2449mN'], array_intersect_key($data['merchant'], ['affiliate_link' => true, 'erid' => true]));
        $data = McpTester::structured(self::mcp(200, Fixtures::read('merchants/shops.xml'))->call('list_merchants', ['search' => 'KOMUS'], self::TOKEN));
        self::assertSame(['2SDnjckjwUs'], array_column((array) $data['merchants'], 'erid'));

        self::assertSame('[not_found] Магазина 1 нет', McpTester::failure(self::mcp(200, Fixtures::read('merchants/shops.xml'))->call('get_merchant', ['merchant_id' => 1], self::TOKEN)));
        self::assertStringStartsWith('[invalid_arguments] ', McpTester::failure((new McpTester())->call('get_merchant')), 'merchant_id обязателен');

        $data = McpTester::structured(self::mcp(200, Fixtures::read('merchants/shops.xml'))->call('list_merchant_categories', [], self::TOKEN));
        self::assertShape(['categories' => ['[]' => ['id' => 'int', 'name' => 'string|null']]], $data);
        self::assertNotEmpty($data['categories']);
    }

    public function testMerchantToolsWithoutTokenUsePublicCatalog(): void
    {
        $mcp = self::mcp(200, Fixtures::read('merchants/shops-public.xml'));
        $data = McpTester::structured($mcp->call('get_merchant', ['merchant_id' => 105263]));
        self::assertShape(['merchant' => JsonShapes::MERCHANT], $data);
        self::assertIsArray($data['merchant']);
        self::assertSame(['affiliate_link' => null], array_intersect_key($data['merchant'], ['affiliate_link' => true]));
        self::assertArrayNotHasKey('api_token', $mcp->cli->transport->lastRequest()->query());

        $mcp = self::mcp(200, Fixtures::read('merchants/shops-public.xml'));
        $data = McpTester::structured($mcp->call('list_merchant_categories'));
        self::assertNotEmpty($data['categories']);
        self::assertArrayNotHasKey('api_token', $mcp->cli->transport->lastRequest()->query());
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, array<string, string>, bool}>
     */
    public static function merchantToolModes(): iterable
    {
        foreach (['list_merchants' => [], 'get_merchant' => ['merchant_id' => 105263], 'list_merchant_categories' => []] as $tool => $arguments) {
            yield "{$tool} без токена" => [$tool, $arguments, [], true];
            yield "{$tool} с токеном" => [$tool, $arguments, self::TOKEN, false];
        }
    }

    /**
     * @param array<string, mixed>  $arguments
     * @param array<string, string> $env
     */
    #[DataProvider('merchantToolModes')]
    public function testMerchantToolsChoosePublicModeByToken(string $tool, array $arguments, array $env, bool $public): void
    {
        $repository = new FakeMerchantRepository((new MerchantXmlParser())->parse(Fixtures::read('merchants/shops.xml')));
        $mcp = new McpTester(build: static fn (): GdeSlon => new GdeSlon(new FakeHttpTransport(), null, $repository));

        McpTester::structured($mcp->call($tool, $arguments, $env));

        self::assertSame($public, $repository->lastPublic());
    }

    public function testSearch(): void
    {
        $mcp = self::mcp(200, Fixtures::read('search/search.xml'));
        $data = McpTester::structured($mcp->call('search_offers', [
            'query' => 'платье красное', 'merchant_ids' => [107054, 111211], 'exclude_category_ids' => [26], 'limit' => 8, 'page' => 2, 'sort' => 'partner_benefit',
        ], self::TOKEN));

        $query = $mcp->cli->transport->lastRequest()->query();
        self::assertSame('платье красное', $query['q'] ?? null);
        self::assertSame('107054,111211', $query['m'] ?? null);
        self::assertSame('26', $query['no_tid'] ?? null);
        self::assertSame(8, $query['l'] ?? null);
        self::assertSame(2, $query['p'] ?? null);
        self::assertSame('partner_benefit', $query['order'] ?? null);
        self::assertShape([
            'query' => 'string|null', 'page' => 'int', 'limit' => 'int', 'effective_limit' => 'int', 'degraded' => 'bool', 'total' => 'int|null',
            'next_page' => 'int|null', 'offers' => ['[]' => JsonShapes::OFFER], 'skipped' => ['[]' => 'string'],
        ], $data);
        self::assertSame(3, $data['next_page']);
        self::assertSame(8, $data['effective_limit']);
        self::assertFalse($data['degraded']);
        self::assertIsArray($data['offers']);
        self::assertCount(7, $data['offers']);
        self::assertIsArray($data['offers'][0]);
        self::assertSame(['amount' => '100', 'currency' => 'RUB'], $data['offers'][0]['price']);
        self::assertIsArray($data['offers'][2]);
        self::assertNull($data['offers'][2]['charge']);
        self::assertSame('2SDnjcZqvUG', $data['offers'][0]['erid']);
        self::assertSame('107054:578237', $data['offers'][0]['key']);
        self::assertIsArray($data['offers'][3]);
        self::assertNull($data['offers'][3]['erid']);
        self::assertIsArray($data['skipped']);
        self::assertCount(1, $data['skipped']);

        $data = McpTester::structured(self::mcp(200, Fixtures::read('search/search-empty.xml'))->call('search_offers', ['query' => 'x'], self::TOKEN));
        self::assertSame([], $data['offers']);
        self::assertNull($data['next_page']);

        foreach ([['limit' => 101], ['page' => 0], ['page' => 1001, 'limit' => 10], ['sort' => 'partner-benefit'], ['merchant_ids' => [0]],
            ['limit' => 101, 'category_ids' => [1113], 'include_subcategories' => true],
            ['page' => 1001, 'limit' => 10, 'category_ids' => [1113], 'include_subcategories' => true],
            ['parked_domain' => 'ftp://x', 'category_ids' => [1113], 'include_subcategories' => true],
            ['merchant_ids' => [5], 'exclude_merchant_ids' => [5], 'category_ids' => [1113], 'include_subcategories' => true]] as $arguments) {
            $mcp = new McpTester();
            self::assertStringStartsWith('[invalid_arguments] ', McpTester::failure($mcp->call('search_offers', $arguments, self::TOKEN)), (string) json_encode($arguments));
            self::assertSame([], $mcp->cli->transport->requests());
        }

        self::assertStringStartsWith('[access] ', McpTester::failure(self::mcp(403, 'This affiliate token does not exists')->call('search_offers', [], self::TOKEN)));
        $mcp = self::mcp(404, "There have been validation errors: [ { param: '_gs_at', msg: 'Invalid value', value: 'secret-token-0123' } ]");
        self::assertStringStartsWith('[access] ', McpTester::failure($mcp->call('search_offers', [], self::TOKEN)), 'SDK переводит эхо _gs_at в AuthenticationException');
        self::assertStringNotContainsString('secret-token-0123', $mcp->cli->stdout . $mcp->cli->stderr);
    }

    public function testSearchWithSubcategoriesExpandsIncludedAndExcluded(): void
    {
        $mcp = self::categoriesThenSearch();
        $data = McpTester::structured($mcp->call('search_offers', [
            'query' => 'тренч', 'category_ids' => [1113], 'exclude_category_ids' => [1216], 'include_subcategories' => true,
        ], self::TOKEN));

        $requests = $mcp->cli->transport->requests();
        self::assertCount(2, $requests);
        self::assertSame('https://api.gdeslon.ru/gdeslon-categories.json', $requests[0]->url());
        $query = $requests[1]->query();
        self::assertSame('1113,1114,1128,1522', $query['tid'] ?? null);
        self::assertArrayNotHasKey('no_tid', $query, 'исключённые уже вычтены из tid');
        self::assertSame('тренч', $query['q'] ?? null);
        self::assertCount(7, (array) $data['offers']);
    }

    public function testSearchWithSubcategoriesExclusionWins(): void
    {
        $mcp = self::categoriesThenSearch();
        McpTester::structured($mcp->call('search_offers', [
            'category_ids' => [1216, 1231], 'exclude_category_ids' => [1230, 1231], 'include_subcategories' => true,
        ], self::TOKEN));

        $query = $mcp->cli->transport->lastRequest()->query();
        self::assertSame('1216,1217', $query['tid'] ?? null);
        self::assertArrayNotHasKey('no_tid', $query);
    }

    public function testSearchWithSubcategoriesOnlyExcluded(): void
    {
        $mcp = self::categoriesThenSearch();
        McpTester::structured($mcp->call('search_offers', ['exclude_category_ids' => [1230], 'include_subcategories' => true], self::TOKEN));

        $query = $mcp->cli->transport->lastRequest()->query();
        self::assertArrayNotHasKey('tid', $query);
        self::assertSame('1230,1231,1232', $query['no_tid'] ?? null);
    }

    public function testSearchWithSubcategoriesWhenEverythingIsExcluded(): void
    {
        $mcp = self::mcp(200, Fixtures::read('categories/categories.json'));
        $text = McpTester::failure($mcp->call('search_offers', [
            'category_ids' => [1216], 'exclude_category_ids' => [1113], 'include_subcategories' => true,
        ], self::TOKEN));

        self::assertStringStartsWith('[invalid_arguments] ', $text);
        self::assertStringContainsString('исключены', $text);
        self::assertCount(1, $mcp->cli->transport->requests(), 'поиск без tid расширил бы выдачу');
    }

    public function testSearchWithSubcategoriesUnknownCategoryIsNotFound(): void
    {
        foreach ([[999999, ['category_ids' => [1113, 999999]]], [277, ['category_ids' => [1113], 'exclude_category_ids' => [277]]]] as [$missing, $arguments]) {
            $mcp = self::mcp(200, Fixtures::read('categories/categories.json'));

            $text = McpTester::failure($mcp->call('search_offers', $arguments + ['include_subcategories' => true], self::TOKEN));

            self::assertSame(sprintf('[not_found] Категории %d нет', $missing), $text);
            self::assertCount(1, $mcp->cli->transport->requests(), 'поиск не идёт');
        }
    }

    public function testSearchWithSubcategoriesWithoutCategoryListsDoesNotLoadCategories(): void
    {
        $mcp = self::mcp(200, Fixtures::read('search/search.xml'));
        McpTester::structured($mcp->call('search_offers', ['query' => 'тренч', 'merchant_ids' => [107054], 'include_subcategories' => true], self::TOKEN));

        self::assertCount(1, $mcp->cli->transport->requests());
        self::assertSame('https://api.gdeslon.ru/api/search.xml', $mcp->cli->transport->lastRequest()->url());
        self::assertArrayNotHasKey('tid', $mcp->cli->transport->lastRequest()->query());
    }

    public function testSearchWithoutSubcategoriesKeepsCategoriesAsIs(): void
    {
        foreach ([[], ['include_subcategories' => false]] as $flag) {
            $mcp = self::mcp(200, Fixtures::read('search/search.xml'));
            McpTester::structured($mcp->call('search_offers', ['category_ids' => [1113], 'exclude_category_ids' => [1216]] + $flag, self::TOKEN));

            self::assertCount(1, $mcp->cli->transport->requests());
            self::assertSame('1113', $mcp->cli->transport->lastRequest()->query()['tid'] ?? null);
            self::assertSame('1216', $mcp->cli->transport->lastRequest()->query()['no_tid'] ?? null);
        }

        $mcp = new McpTester();
        self::assertStringStartsWith('[invalid_arguments] ', McpTester::failure($mcp->call('search_offers', ['category_ids' => [1113], 'exclude_category_ids' => [1113]], self::TOKEN)));
    }

    public function testSearchWithSubcategoriesCategoriesFailure(): void
    {
        $mcp = self::mcp(500, 'Something broken!');

        self::assertStringStartsWith('[failed] ', McpTester::failure($mcp->call('search_offers', ['category_ids' => [1113], 'include_subcategories' => true], self::TOKEN)));
        self::assertCount(1, $mcp->cli->transport->requests());
        self::assertSame('https://api.gdeslon.ru/gdeslon-categories.json', $mcp->cli->transport->lastRequest()->url());
    }

    public function testIncludeSubcategoriesMustBeBoolean(): void
    {
        foreach (['yes', 1, 'true', []] as $value) {
            $mcp = new McpTester();
            $text = McpTester::failure($mcp->call('search_offers', ['category_ids' => [1113], 'include_subcategories' => $value], self::TOKEN));

            self::assertStringStartsWith('[invalid_arguments] ', $text);
            self::assertStringContainsString('include_subcategories', $text);
            self::assertSame([], $mcp->cli->transport->requests());
        }

        $mcp = new McpTester();
        $mcp->send([McpTester::initialize(), ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']]);
        $tools = (array) McpTester::result($mcp->responses[1])['tools'];
        $search = array_values(array_filter($tools, static fn (mixed $tool): bool => is_array($tool) && $tool['name'] === 'search_offers'))[0] ?? null;
        self::assertIsArray($search);
        self::assertIsArray($search['inputSchema']);
        self::assertIsArray($search['inputSchema']['properties']);
        self::assertIsArray($search['inputSchema']['properties']['include_subcategories']);
        self::assertSame('boolean', $search['inputSchema']['properties']['include_subcategories']['type']);
        self::assertFalse($search['inputSchema']['properties']['include_subcategories']['default']);
    }

    public function testOrders(): void
    {
        $mcp = self::mcp(200, '[]');
        $data = McpTester::structured($mcp->call('list_orders', [
            'date_field' => 'last_updated', 'until' => '2026-10-07', 'days' => 7, 'states' => ['confirmed', 'paid'], 'type' => 'product', 'merchant_id' => 2573, 'sub_id' => 'blog',
        ], self::SALES));
        self::assertSame('Basic ' . base64_encode('1234:test-api-key'), $mcp->cli->transport->lastRequest()->header('Authorization'));
        self::assertSame('{"last_updated_at":{"date":"2026-10-07","period":7},"merchant_id":2573,"state":[3,4],"type":0,"sub_id":"blog"}', $mcp->cli->transport->lastRequest()->body());
        self::assertSame(0, $data['total']);

        $mcp = new McpTester((new FakeHttpTransport())->willReturn(200, '[]'), FrozenClock::at('2026-10-06T22:30:00Z'));
        McpTester::structured($mcp->call('list_orders', [], self::SALES));
        self::assertSame('{"created_at":{"date":"2026-10-07","period":30}}', $mcp->cli->transport->lastRequest()->body(), 'сегодня — по Москве');

        $mcp = self::mcp(200, Fixtures::read('orders/orders-synthetic.json'));
        $data = McpTester::structured($mcp->call('list_orders', ['limit' => 3], self::SALES));
        self::assertShape(['orders' => ['[]' => JsonShapes::ORDER], 'skipped' => ['[]' => 'string']] + self::PAGE, $data);
        self::assertSame(4, $data['total']);
        self::assertSame(3, $data['next_offset']);
        self::assertStringNotContainsString('test-api-key', $mcp->cli->stdout . $mcp->cli->stderr);

        $orders = Fixtures::read('orders/orders-synthetic.json');
        $broken = substr($orders, 0, (int) strrpos($orders, ']')) . ', {"id": "X-1"}]';
        $data = McpTester::structured(self::mcp(200, $broken)->call('list_orders', [], self::SALES));
        self::assertSame(4, $data['total']);
        self::assertCount(1, (array) $data['skipped'], 'битая запись — в skipped, вызов успешен');

        self::assertStringStartsWith('[access] ', McpTester::failure(self::mcp(401, '{"detail":"Недопустимые имя пользователя или пароль."}')->call('list_orders', [], self::SALES)));
        self::assertStringStartsWith('[failed] ', McpTester::failure(self::mcp(500, Fixtures::read('orders/error-500.html'))->call('list_orders', [], self::SALES)));
        foreach ([['days' => 0], ['days' => 3661], ['until' => '2026-02-30'], ['states' => ['foo']], ['type' => 'lead2'], ['date_field' => 'last-updated']] as $arguments) {
            $mcp = new McpTester();
            self::assertStringStartsWith('[invalid_arguments] ', McpTester::failure($mcp->call('list_orders', $arguments, self::SALES)), (string) json_encode($arguments));
            self::assertSame([], $mcp->cli->transport->requests());
        }
    }

    public function testLostOrderClaims(): void
    {
        $mcp = self::mcp(200, Fixtures::read('lost-orders/claims-synthetic.json'));
        $data = McpTester::structured($mcp->call('list_lost_order_claims', ['merchant_id' => 2573, 'from' => '2026-09-01', 'until' => '2026-09-30', 'claim_state' => 'in_work'], self::TOKEN));
        self::assertSame('https://gdeslon.ru/api/v1/lost-orders/?merchant_id=2573&start_date=2026-09-01&end_date=2026-09-30&ticket_state=in_work', $mcp->cli->transport->lastRequest()->uri());
        self::assertSame('Bearer secret-token-0123', $mcp->cli->transport->lastRequest()->header('Authorization'));
        self::assertShape(['claims' => ['[]' => JsonShapes::CLAIM], 'skipped' => ['[]' => 'string']] + self::PAGE, $data);

        self::assertStringContainsString('merchant_id:', McpTester::failure(self::mcp(400, Fixtures::read('lost-orders/error-400-merchant.json'))->call('list_lost_order_claims', ['merchant_id' => 1], self::TOKEN)));
        self::assertStringStartsWith('[invalid_arguments] ', McpTester::failure((new McpTester())->call('list_lost_order_claims', ['claim_state' => 'in-work'], self::TOKEN)));
        self::assertStringStartsWith('[invalid_arguments] ', McpTester::failure((new McpTester())->call('list_lost_order_claims', ['from' => '2026-10-01', 'until' => '2026-09-01'], self::TOKEN)));

        $data = McpTester::structured(self::mcp(200, Fixtures::read('lost-orders/claim-synthetic.json'))->call('get_lost_order_claim', ['claim_id' => 5796], self::TOKEN));
        self::assertShape(['claim' => JsonShapes::CLAIM], $data);
        self::assertSame('[not_found] Заявки 5796 нет', McpTester::failure(self::mcp(404, Fixtures::read('lost-orders/error-404.json'))->call('get_lost_order_claim', ['claim_id' => 5796], self::TOKEN)));
    }

    public function testCouponsAreMasked(): void
    {
        $env = ['GDESLON_API_TOKEN' => self::COUPON_TOKEN];
        $mcp = self::mcp(200, Fixtures::read('coupons/coupons.xml'));
        $data = McpTester::structured($mcp->call('list_coupons', ['merchant_ids' => [99157], 'kind_ids' => [1, 14]], $env));
        self::assertSame('https://gdeslon.ru/api/coupons.xml?api_token=' . self::COUPON_TOKEN . '&merchant_id=99157&kind=1&kind=14', $mcp->cli->transport->lastRequest()->uri());
        self::assertShape(['coupons' => ['[]' => JsonShapes::COUPON], 'kinds' => ['[]' => JsonShapes::COUPON_KIND], 'skipped' => ['[]' => 'string']] + self::PAGE, $data);
        self::assertStringContainsString('/ck/***/', $mcp->cli->stdout);
        self::assertStringNotContainsString(self::COUPON_TOKEN, $mcp->cli->stdout . $mcp->cli->stderr);

        $mcp = new McpTester((new FakeHttpTransport())->willReturn(200, Fixtures::read('coupons/coupons.xml')), FrozenClock::at('2026-11-15T10:00:00Z'));
        $data = McpTester::structured($mcp->call('list_coupons', ['active_only' => true, 'limit' => 2], $env));
        self::assertSame(6, $data['total']);
        self::assertCount(2, (array) $data['coupons']);
        self::assertSame(2, $data['next_offset']);

        self::assertStringContainsString('merchant_id:', McpTester::failure(self::mcp(400, Fixtures::read('coupons/error-400-merchant.xml'))->call('list_coupons', ['merchant_ids' => [23707]], $env)));
        self::assertSame(0, McpTester::structured(self::mcp(200, Fixtures::read('coupons/coupons-empty.xml'))->call('list_coupons', [], $env))['total']);
        self::assertSame(10, count((array) McpTester::structured(self::mcp(200, Fixtures::read('coupons/coupons-broken.xml'))->call('list_coupons', [], $env))['skipped']));

        $data = McpTester::structured(self::mcp(200, Fixtures::read('coupons/coupons.xml'))->call('get_coupon', ['coupon_id' => 336004], $env));
        self::assertShape(['coupon' => JsonShapes::COUPON], $data);
        self::assertIsArray($data['coupon']);
        self::assertSame('2SDnjTEST001', $data['coupon']['erid']);
        self::assertSame('[not_found] Купона 1 нет', McpTester::failure(self::mcp(200, Fixtures::read('coupons/coupons.xml'))->call('get_coupon', ['coupon_id' => 1], $env)));
        $kinds = McpTester::structured(self::mcp(200, Fixtures::read('coupons/coupons.xml'))->call('list_coupon_kinds', [], $env));
        self::assertShape(['kinds' => ['[]' => JsonShapes::COUPON_KIND]], $kinds);
        self::assertContains('Black Friday', array_column((array) $kinds['kinds'], 'name'));
    }

    public function testRevealLinks(): void
    {
        $env = ['GDESLON_API_TOKEN' => self::COUPON_TOKEN];
        $mcp = self::mcp(200, Fixtures::read('coupons/coupons.xml'));
        McpTester::structured($mcp->call('get_coupon', ['coupon_id' => 336004], $env, ['mcp', '--no-cache', '--reveal-links']));

        self::assertStringContainsString('/ck/' . self::COUPON_TOKEN . '/336004', $mcp->cli->stdout);
        self::assertStringContainsString('токен', $mcp->cli->stderr);
        self::assertStringNotContainsString(self::COUPON_TOKEN, $mcp->cli->stderr);
    }

    public function testOldClientGetsTextOnly(): void
    {
        $mcp = self::mcp(200, Fixtures::read('categories/categories.json'));
        $mcp->send([
            McpTester::initialize('2025-03-26'),
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
            ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'get_categories', 'arguments' => ['limit' => 1]]],
        ]);

        $tools = (array) McpTester::result($mcp->responses[1])['tools'];
        self::assertIsArray($tools[0]);
        self::assertArrayNotHasKey('outputSchema', $tools[0], 'без structuredContent нельзя объявлять outputSchema');
        $result = McpTester::result($mcp->responses[2]);
        self::assertArrayNotHasKey('structuredContent', $result);
        self::assertStringContainsString('"categories":[', McpTester::text($result));
    }

    public function testSearchDegraded(): void
    {
        $mcp = new McpTester((new FakeHttpTransport())
            ->willFail(28, 'Operation timed out')
            ->willReturn(200, Fixtures::read('search/search-l5-p1.xml'))
            ->willReturn(200, Fixtures::read('search/search-l5-p2.xml')));

        $data = McpTester::structured($mcp->call('search_offers', ['query' => 'платье'], self::TOKEN));

        self::assertCount(3, $mcp->cli->transport->requests());
        self::assertSame(10, $data['limit']);
        self::assertSame(5, $data['effective_limit']);
        self::assertTrue($data['degraded']);
        self::assertSame(8140, $data['total']);
        self::assertSame(2, $data['next_page']);
        self::assertIsArray($data['offers']);
        self::assertCount(10, $data['offers']);
    }

    private static function mcp(int $status, string $body): McpTester
    {
        return new McpTester((new FakeHttpTransport())->willReturn($status, $body));
    }

    private static function categoriesThenSearch(): McpTester
    {
        return new McpTester((new FakeHttpTransport())
            ->willReturn(200, Fixtures::read('categories/categories.json'))
            ->willReturn(200, Fixtures::read('search/search.xml')));
    }

    /**
     * @param array<array-key, mixed>|string $expected
     */
    private static function assertShape(array|string $expected, mixed $actual, string $path = '$'): void
    {
        if (is_string($expected)) {
            self::assertContains(get_debug_type($actual), explode('|', $expected), $path);

            return;
        }
        if (array_key_exists('?', $expected)) {
            if ($actual !== null) {
                self::assertIsArray($expected['?']);
                self::assertShape($expected['?'], $actual, $path);
            }

            return;
        }
        self::assertIsArray($actual, $path);
        if (array_key_exists('[]', $expected)) {
            self::assertTrue(array_is_list($actual), $path);
            foreach ($actual as $i => $item) {
                self::assertTrue(is_array($expected['[]']) || is_string($expected['[]']));
                self::assertShape($expected['[]'], $item, $path . '[' . $i . ']');
            }

            return;
        }
        self::assertSame(array_keys($expected), array_keys($actual), $path);
        foreach ($expected as $key => $shape) {
            self::assertTrue(is_array($shape) || is_string($shape));
            self::assertShape($shape, $actual[$key], $path . '.' . $key);
        }
    }
}

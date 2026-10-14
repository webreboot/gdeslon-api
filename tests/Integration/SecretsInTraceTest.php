<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Config;
use Webreboot\GdeSlon\Domain\Claims\ClaimAttachment;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaimUnconfirmedException;
use Webreboot\GdeSlon\Domain\Claims\NewLostOrderClaim;
use Webreboot\GdeSlon\Exception\AuthenticationException;
use Webreboot\GdeSlon\Exception\ConnectTimeoutException;
use Webreboot\GdeSlon\Exception\HttpException;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Exception\TimeoutException;
use Webreboot\GdeSlon\Exception\TransportException;
use Webreboot\GdeSlon\Exception\UnexpectedResponseException;
use Webreboot\GdeSlon\GdeSlon;
use Webreboot\GdeSlon\Infrastructure\Api\ApiClient;
use Webreboot\GdeSlon\Infrastructure\Api\Catalog\HttpMerchantRepository;
use Webreboot\GdeSlon\Infrastructure\Api\Catalog\HttpProductCatalog;
use Webreboot\GdeSlon\Infrastructure\Api\Claims\HttpLostOrderClaims;
use Webreboot\GdeSlon\Infrastructure\Api\Promo\HttpCouponFeed;
use Webreboot\GdeSlon\Infrastructure\Api\Sales\HttpOrderRepository;
use Webreboot\GdeSlon\Infrastructure\Cache\CacheStore;
use Webreboot\GdeSlon\Infrastructure\Feed\FeedUrl;
use Webreboot\GdeSlon\Infrastructure\Feed\PartResponse;
use Webreboot\GdeSlon\Infrastructure\Feed\RangedFeedDownloader;
use Webreboot\GdeSlon\Infrastructure\Http\HttpRequest;
use Webreboot\GdeSlon\Infrastructure\Http\HttpResponse;
use Webreboot\GdeSlon\Infrastructure\Http\HttpTransport;
use Webreboot\GdeSlon\Infrastructure\Http\RangeTransport;
use Webreboot\GdeSlon\Infrastructure\Http\TransportFactory;
use Webreboot\GdeSlon\Interface\Postback\HeaderSecret;
use Webreboot\GdeSlon\Interface\Postback\PostbackReceiver;
use Webreboot\GdeSlon\Testing\FakeHttpTransport;
use Webreboot\GdeSlon\Testing\FrozenClock;
use Webreboot\GdeSlon\Testing\InMemoryCacheStore;
use Webreboot\GdeSlon\Tests\Support\FakeFeedHost;
use Webreboot\GdeSlon\Tests\Support\Fixtures;
use Webreboot\GdeSlon\Tests\Support\TraceSecrets;
use Webreboot\GdeSlon\Tests\Support\ZipBuilder;

final class SecretsInTraceTest extends TestCase
{
    use TraceSecrets;

    private const TOKEN = '0a1b2c3d4e0a1b2c3d4e0a1b2c3d4e0a1b2c3d4e';

    private const USER_ID = '1234';

    private const FEED_SECRET = 'Zk3feedSecret0123456789abcdef';

    private const ENTRY_SECRET = 'EntrySecretQx7ab3ZpW9mK2vT5nR8yL1cF4hJ6d';

    private const API_KEY = 'test-api-key-0123';

    /**
     * @return iterable<string, array{\Closure(FakeHttpTransport): FakeHttpTransport, class-string<\Throwable>}>
     */
    public static function searchFailures(): iterable
    {
        yield '403' => [static fn (FakeHttpTransport $t) => $t->willReturn(403, 'This affiliate token does not exists'), AuthenticationException::class];
        yield '401' => [static fn (FakeHttpTransport $t) => $t->willReturn(401, ''), AuthenticationException::class];
        yield '500' => [static fn (FakeHttpTransport $t) => $t->willReturn(500, '<h1>Internal Server Error</h1>'), HttpException::class];
        yield '404 с эхом токена' => [
            static fn (FakeHttpTransport $t) => $t->willReturn(404, "There have been validation errors: [ { param: '_gs_at', msg: 'Invalid value', value: '" . self::TOKEN . "' } ]"),
            AuthenticationException::class,
        ];
        yield 'таймаут' => [
            static fn (FakeHttpTransport $t) => $t->willFail(28, 'Operation timed out')->willFail(28, 'Operation timed out')->willFail(28, 'Operation timed out'),
            TimeoutException::class,
        ];
        yield 'таймаут, затем 500' => [
            static fn (FakeHttpTransport $t) => $t->willFail(28, 'Operation timed out')->willReturn(500, '<h1>Internal Server Error</h1>'),
            HttpException::class,
        ];
        yield 'таймаут, затем 420' => [
            static fn (FakeHttpTransport $t) => $t->willFail(28, 'Operation timed out')
                ->willReturn(200, Fixtures::read('search/search-l5-p1.xml'))
                ->willReturn(420, 'Rate limit exceeded!'),
            HttpException::class,
        ];
        yield 'таймаут части, затем таймаут соединения' => [
            static fn (FakeHttpTransport $t) => $t->willFail(28, 'Operation timed out')->willFail(28, 'Operation timed out')->willTimeOutOnConnect(),
            ConnectTimeoutException::class,
        ];
        yield 'ошибка соединения' => [static fn (FakeHttpTransport $t) => $t->willFail(7), TransportException::class];
        yield 'таймаут соединения' => [static fn (FakeHttpTransport $t) => $t->willTimeOutOnConnect(), ConnectTimeoutException::class];
        yield 'таймаут, затем таймаут соединения' => [
            static fn (FakeHttpTransport $t) => $t->willFail(28, 'Operation timed out')
                ->willReturn(200, Fixtures::read('search/search-l5-p1.xml'))
                ->willTimeOutOnConnect(),
            ConnectTimeoutException::class,
        ];
    }

    /**
     * @param \Closure(FakeHttpTransport): FakeHttpTransport $response
     * @param class-string<\Throwable>                       $expected
     */
    #[DataProvider('searchFailures')]
    public function testQueryTokenGsAtInSearch(\Closure $response, string $expected): void
    {
        self::requireSensitiveParameter();
        $gdeslon = GdeSlon::create(new Config(apiToken: self::TOKEN), $response(new FakeHttpTransport()));

        $e = self::catchWithTraceArgs(static fn () => $gdeslon->search('платье'), $expected);

        self::assertTraceHidesSecrets($e, self::TOKEN);
    }

    public function testOwnDecoratorUnderRangeHidesToken(): void
    {
        self::requireSensitiveParameter();
        $config = new Config(apiToken: self::TOKEN);
        $decorator = new class ((new FakeHttpTransport())->willFail(7)) implements HttpTransport {
            public function __construct(private readonly HttpTransport $inner)
            {
            }

            public function send(#[\SensitiveParameter] HttpRequest $request): HttpResponse
            {
                return $this->inner->send($request);
            }
        };
        $gdeslon = GdeSlon::create($config, TransportFactory::fromConfig($config, $decorator));

        $e = self::catchWithTraceArgs(static fn () => $gdeslon->search('платье'), TransportException::class);

        $functions = array_map(static fn (array $frame): string => ($frame['class'] ?? '') . '::' . $frame['function'], $e->getTrace());
        self::assertContains(RangeTransport::class . '::send', $functions);
        self::assertContains($decorator::class . '::send', $functions);
        self::assertTraceHidesSecrets($e, self::TOKEN);
    }

    public function testSearchValidationErrorKeepsCauseWithoutToken(): void
    {
        self::requireSensitiveParameter();
        $gdeslon = GdeSlon::create(new Config(apiToken: self::TOKEN), (new FakeHttpTransport())->willReturn(404, "[ { param: '_gs_at', value: '" . self::TOKEN . "' } ]"));

        $e = self::catchWithTraceArgs(static fn () => $gdeslon->search('платье'), AuthenticationException::class);

        self::assertInstanceOf(HttpException::class, $e->getPrevious());
        self::assertStringNotContainsString(self::TOKEN, $e->responseSnippet());
        self::assertTraceHidesSecrets($e, self::TOKEN);
    }

    /**
     * @return iterable<string, array{\Closure(FakeHttpTransport): FakeHttpTransport, class-string<\Throwable>}>
     */
    public static function merchantFailures(): iterable
    {
        yield '401' => [static fn (FakeHttpTransport $t) => $t->willReturn(401, ''), AuthenticationException::class];
        yield '500' => [static fn (FakeHttpTransport $t) => $t->willReturn(500, ''), HttpException::class];
        yield 'ошибка соединения' => [static fn (FakeHttpTransport $t) => $t->willFail(7), TransportException::class];
        yield 'токен не принят: нет партнёрских ссылок' => [
            static fn (FakeHttpTransport $t) => $t->willReturn(200, Fixtures::read('merchants/shops-public.xml')),
            AuthenticationException::class,
        ];
        yield '304 без сохранённой копии' => [static fn (FakeHttpTransport $t) => $t->willReturn(304, ''), UnexpectedResponseException::class];
    }

    /**
     * @param \Closure(FakeHttpTransport): FakeHttpTransport $response
     * @param class-string<\Throwable>                       $expected
     */
    #[DataProvider('merchantFailures')]
    public function testQueryApiTokenInMerchants(\Closure $response, string $expected): void
    {
        self::requireSensitiveParameter();
        $gdeslon = GdeSlon::create(new Config(apiToken: self::TOKEN), $response(new FakeHttpTransport()));

        $e = self::catchWithTraceArgs(static fn () => $gdeslon->merchants(), $expected);

        self::assertTraceHidesSecrets($e, self::TOKEN);
    }

    public function testMerchantsWithStaleCacheAndRejectedToken(): void
    {
        self::requireSensitiveParameter();
        $transport = (new FakeHttpTransport())->willReturn(200, Fixtures::read('merchants/shops.xml'))->willReturn(401, '');
        $gdeslon = GdeSlon::create(new Config(apiToken: self::TOKEN, merchantCacheTtl: 0), $transport, new InMemoryCacheStore());
        $gdeslon->merchants();

        $e = self::catchWithTraceArgs(static fn () => $gdeslon->merchants(), AuthenticationException::class);

        self::assertTraceHidesSecrets($e, self::TOKEN);
    }

    /**
     * @param \Closure(FakeHttpTransport): FakeHttpTransport $response
     * @param class-string<\Throwable>                       $expected
     */
    #[DataProvider('merchantRefreshFailures')]
    public function testMerchantsRefreshFailureHidesTokenInTrace(\Closure $response, string $expected): void
    {
        self::requireSensitiveParameter();
        $transport = (new FakeHttpTransport())->willReturn(200, Fixtures::read('merchants/shops.xml'), ['ETag' => ['W/"e"']]);
        $gdeslon = GdeSlon::create(new Config(apiToken: self::TOKEN), $response($transport), new InMemoryCacheStore(), FrozenClock::at('2026-10-07 12:00:00'));
        $gdeslon->merchants();

        $e = self::catchWithTraceArgs(static fn () => $gdeslon->merchants(refresh: true), $expected);

        self::assertTraceHidesSecrets($e, self::TOKEN);
        self::assertSame('W/"e"', $transport->lastRequest()->header('If-None-Match'));
    }

    /**
     * @return iterable<string, array{\Closure(FakeHttpTransport): FakeHttpTransport, class-string<\Throwable>}>
     */
    public static function merchantRefreshFailures(): iterable
    {
        yield '401' => [static fn (FakeHttpTransport $t) => $t->willReturn(401, ''), AuthenticationException::class];
        yield 'токен не принят: нет партнёрских ссылок' => [
            static fn (FakeHttpTransport $t) => $t->willReturn(200, Fixtures::read('merchants/shops-public.xml')),
            AuthenticationException::class,
        ];
        yield 'битый XML' => [static fn (FakeHttpTransport $t) => $t->willReturn(200, '<shops><shop>'), UnexpectedResponseException::class];
        yield 'таймаут' => [static fn (FakeHttpTransport $t) => $t->willFail(28, 'Operation timed out'), TimeoutException::class];
        yield 'таймаут соединения' => [static fn (FakeHttpTransport $t) => $t->willTimeOutOnConnect(), ConnectTimeoutException::class];
    }

    /**
     * @return iterable<string, array{\Closure(FakeHttpTransport): FakeHttpTransport, class-string<\Throwable>}>
     */
    public static function couponFailures(): iterable
    {
        yield '401' => [static fn (FakeHttpTransport $t) => $t->willReturn(401, Fixtures::read('coupons/error-401-bad-token.xml')), AuthenticationException::class];
        yield '500' => [static fn (FakeHttpTransport $t) => $t->willReturn(500, ''), HttpException::class];
        yield 'ошибка соединения' => [static fn (FakeHttpTransport $t) => $t->willFail(7), TransportException::class];
        yield 'таймаут соединения' => [static fn (FakeHttpTransport $t) => $t->willTimeOutOnConnect(), ConnectTimeoutException::class];
    }

    /**
     * @param \Closure(FakeHttpTransport): FakeHttpTransport $response
     * @param class-string<\Throwable>                       $expected
     */
    #[DataProvider('couponFailures')]
    public function testQueryApiTokenInCoupons(\Closure $response, string $expected): void
    {
        self::requireSensitiveParameter();
        $gdeslon = GdeSlon::create(new Config(apiToken: self::TOKEN), $response(new FakeHttpTransport()));

        $e = self::catchWithTraceArgs(static fn () => $gdeslon->coupons(), $expected);

        self::assertTraceHidesSecrets($e, self::TOKEN);
    }

    /**
     * @return iterable<string, array{\Closure(FakeHttpTransport): FakeHttpTransport, \Closure(GdeSlon): mixed, class-string<\Throwable>}>
     */
    public static function lostOrderFailures(): iterable
    {
        $list = static fn (GdeSlon $g) => $g->lostOrders();
        yield 'список, 401' => [static fn (FakeHttpTransport $t) => $t->willReturn(401, Fixtures::read('lost-orders/error-401-bad-token.json')), $list, AuthenticationException::class];
        yield 'список, 400 с ошибками полей' => [static fn (FakeHttpTransport $t) => $t->willReturn(400, Fixtures::read('lost-orders/error-400-merchant.json')), $list, HttpException::class];
        yield 'список, 500' => [static fn (FakeHttpTransport $t) => $t->willReturn(500, ''), $list, HttpException::class];
        yield 'список, ошибка соединения' => [static fn (FakeHttpTransport $t) => $t->willFail(7), $list, TransportException::class];
        yield 'заявка, 404 не по формату' => [static fn (FakeHttpTransport $t) => $t->willReturn(404, '<h1>Not Found</h1>'), static fn (GdeSlon $g) => $g->lostOrder(5), HttpException::class];
        $submit = static fn (GdeSlon $g) => $g->submitLostOrderClaim(
            new NewLostOrderClaim('GS123L', '2026-09-24', '554.34', 2573, ClaimAttachment::fromContents('receipt.pdf', "%PDF-1.4\n")),
            checkDuplicates: false,
        );
        yield 'подача, 503' => [static fn (FakeHttpTransport $t) => $t->willReturn(503, ''), $submit, LostOrderClaimUnconfirmedException::class];
        yield 'подача, таймаут' => [static fn (FakeHttpTransport $t) => $t->willFail(28, 'Operation timed out'), $submit, LostOrderClaimUnconfirmedException::class];
        yield 'подача, таймаут соединения' => [static fn (FakeHttpTransport $t) => $t->willTimeOutOnConnect(), $submit, LostOrderClaimUnconfirmedException::class];
        yield 'список, таймаут соединения' => [static fn (FakeHttpTransport $t) => $t->willTimeOutOnConnect(), $list, ConnectTimeoutException::class];
    }

    /**
     * @param \Closure(FakeHttpTransport): FakeHttpTransport $response
     * @param \Closure(GdeSlon): mixed                       $call
     * @param class-string<\Throwable>                       $expected
     */
    #[DataProvider('lostOrderFailures')]
    public function testBearerInLostOrders(\Closure $response, \Closure $call, string $expected): void
    {
        self::requireSensitiveParameter();
        $gdeslon = GdeSlon::create(new Config(apiToken: self::TOKEN), $response(new FakeHttpTransport()), null, FrozenClock::at('2026-09-25 12:00:00'));

        $e = self::catchWithTraceArgs(static fn () => $call($gdeslon), $expected);

        self::assertTraceHidesSecrets($e, self::TOKEN);
    }

    /**
     * @return iterable<string, array{\Closure(FakeHttpTransport): FakeHttpTransport, class-string<\Throwable>}>
     */
    public static function orderFailures(): iterable
    {
        yield '401' => [static fn (FakeHttpTransport $t) => $t->willReturn(401, '{"detail":"Недопустимые имя пользователя или пароль."}'), AuthenticationException::class];
        yield '500' => [static fn (FakeHttpTransport $t) => $t->willReturn(500, Fixtures::read('orders/error-500.html')), HttpException::class];
        yield 'битый JSON' => [static fn (FakeHttpTransport $t) => $t->willReturn(200, '[{'), UnexpectedResponseException::class];
        yield 'ошибка соединения' => [static fn (FakeHttpTransport $t) => $t->willFail(7), TransportException::class];
    }

    /**
     * @param \Closure(FakeHttpTransport): FakeHttpTransport $response
     * @param class-string<\Throwable>                       $expected
     */
    #[DataProvider('orderFailures')]
    public function testBasicInOrders(\Closure $response, string $expected): void
    {
        self::requireSensitiveParameter();
        $gdeslon = GdeSlon::create(new Config(userId: self::USER_ID, apiKey: self::API_KEY), $response(new FakeHttpTransport()), null, FrozenClock::at('2026-09-25 12:00:00'));

        $e = self::catchWithTraceArgs(static fn () => $gdeslon->orders(), $expected);

        self::assertTraceHidesSecrets($e, self::API_KEY, base64_encode(self::USER_ID . ':' . self::API_KEY));
    }

    /**
     * @return iterable<string, array{\Closure(): object}>
     */
    public static function objectsWithSecrets(): iterable
    {
        $client = static fn () => new ApiClient(new FakeHttpTransport());
        $secret = static fn () => new HeaderSecret('X-Gdeslon-Secret', self::TOKEN);
        yield 'Config' => [static fn () => new Config(apiToken: self::TOKEN, userId: self::USER_ID, apiKey: self::API_KEY)];
        yield 'GdeSlon' => [static fn () => GdeSlon::create(new Config(apiToken: self::TOKEN, userId: self::USER_ID, apiKey: self::API_KEY), new FakeHttpTransport())];
        $transport = static fn (): HttpTransport => new class implements HttpTransport {
            public function send(#[\SensitiveParameter] HttpRequest $request): HttpResponse
            {
                return new HttpResponse(200, '', []);
            }
        };
        $cache = static fn (): CacheStore => new class implements CacheStore {
            public function get(string $key): ?string
            {
                return null;
            }

            public function set(string $key, string $value): bool
            {
                return true;
            }
        };
        $keys = static fn () => new Config(userId: self::USER_ID, apiKey: self::API_KEY);
        yield 'GdeSlon с несериализуемым транспортом' => [static fn () => GdeSlon::create(new Config(apiToken: self::TOKEN), $transport())];
        yield 'GdeSlon только с ключами и несериализуемым транспортом' => [static fn () => GdeSlon::create($keys(), $transport())];
        yield 'GdeSlon только с ключами и несериализуемым кэшем' => [static fn () => GdeSlon::create($keys(), new FakeHttpTransport(), $cache())];
        yield 'HttpRequest' => [static fn () => HttpRequest::get('https://api.gdeslon.ru/api/search.xml', ['_gs_at' => self::TOKEN])];
        yield 'HttpRequest в массиве' => [static fn () => (object) ['request' => HttpRequest::post('https://gdeslon.ru/api/orders/', '{}', ['Authorization' => 'Basic ' . base64_encode(self::USER_ID . ':' . self::API_KEY)])]];
        yield 'HeaderSecret' => [$secret];
        yield 'PostbackReceiver' => [static fn () => new PostbackReceiver($secret())];
        yield 'HttpProductCatalog' => [static fn () => new HttpProductCatalog($client(), self::TOKEN)];
        yield 'HttpMerchantRepository' => [static fn () => new HttpMerchantRepository($client(), self::TOKEN)];
        yield 'HttpCouponFeed' => [static fn () => new HttpCouponFeed($client(), self::TOKEN)];
        yield 'HttpLostOrderClaims' => [static fn () => new HttpLostOrderClaims($client(), self::TOKEN)];
        yield 'HttpOrderRepository' => [static fn () => new HttpOrderRepository($client(), self::USER_ID, self::API_KEY)];
    }

    /**
     * @param \Closure(): object $create
     */
    #[DataProvider('objectsWithSecrets')]
    public function testRefusedSerializationDoesNotLeakThroughTrace(\Closure $create): void
    {
        $object = $create();

        $e = self::catchWithTraceArgs(static fn () => serialize($object), InvalidArgumentException::class);

        self::assertStringNotContainsString('InvalidArgumentException.php', $e->getFile(), 'место отказа — __sleep(), а не помощник');
        $trace = var_export(self::packageFrameArgs($e), true);
        foreach ([self::TOKEN, self::API_KEY, base64_encode(self::USER_ID . ':' . self::API_KEY)] as $secret) {
            self::assertStringNotContainsString($secret, $trace);
            self::assertStringNotContainsString($secret, $e->getMessage());
        }
    }

    public function testRefusedSerializationRestoresSettingAndPointsToSleep(): void
    {
        $previous = ini_get('zend.exception_ignore_args');
        try {
            foreach (['0', '1'] as $value) {
                ini_set('zend.exception_ignore_args', $value);
                try {
                    serialize(new Config(apiToken: self::TOKEN));
                    self::fail('Ожидалось исключение');
                } catch (InvalidArgumentException $e) {
                    self::assertSame($value, ini_get('zend.exception_ignore_args'));
                    self::assertStringEndsWith('Config.php', $e->getFile());
                    self::assertStringContainsString('withoutTraceArguments(', (string) (file($e->getFile()) ?: [])[$e->getLine() - 1]);
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previous);
        }
    }

    public function testRefusedSerializationWithoutIniSetIsPackageException(): void
    {
        $code = sprintf(
            'require %s; try { serialize(new %s(apiToken: "%s")); } catch (\Throwable $e) { echo get_class($e), " ", basename($e->getFile()); }',
            var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true),
            Config::class,
            self::TOKEN,
        );
        $process = proc_open([PHP_BINARY, '-d', 'disable_functions=ini_set', '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        /** @var array{1: resource, 2: resource} $pipes */
        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        self::assertSame(InvalidArgumentException::class . ' Config.php', trim($output), 'исключение пакета, место — __sleep()');
    }

    /**
     * @return iterable<string, array{\Closure(FakeFeedHost): FakeFeedHost, class-string<\Throwable>}>
     */
    public static function feedFailures(): iterable
    {
        $page = 'Forbidden: /uploads/exports/' . self::FEED_SECRET . '.zip';
        yield '404' => [static fn (FakeFeedHost $h) => $h->failAt(0, 404), HttpException::class];
        yield '403 со страницей, повторяющей путь' => [static fn (FakeFeedHost $h) => $h->override(1, new PartResponse(403, [], $page)), AuthenticationException::class];
        yield '503 со страницей после повторов' => [static fn (FakeFeedHost $h) => $h->override(1, new PartResponse(503, [], $page))->override(2, new PartResponse(503, [], $page)), HttpException::class];
        yield '500 части после повторов' => [static fn (FakeFeedHost $h) => $h->failAt(1024, 500, 10), HttpException::class];
        yield 'не выгрузка' => [static fn (FakeFeedHost $h) => $h->override(1, new PartResponse(206, ['Content-Range' => 'bytes 0-9/10'], '<html><b>x')), UnexpectedResponseException::class];
        $short = static fn (): PartResponse => new PartResponse(206, ['ETag' => '"e"', 'Content-Range' => 'bytes 0-1023/4400'], substr(self::feedZip(), 0, 500));
        yield 'короткая первая часть с именем записи' => [static fn (FakeFeedHost $h) => $h->override(1, $short())->override(2, $short()), UnexpectedResponseException::class];
        $last = static fn (): PartResponse => new PartResponse(206, ['Content-Range' => 'bytes 0-99/' . strlen(self::feedZip())], substr(self::feedZip(), -100));
        yield 'неверный Content-Range последней части' => [static fn (FakeFeedHost $h) => $h->override(5, $last()), UnexpectedResponseException::class];
    }

    private static function feedZip(): string
    {
        return (new ZipBuilder())->add(self::ENTRY_SECRET . '.xml', str_repeat('<yml_catalog/>', 300), ZipBuilder::STORED)->build();
    }

    /**
     * @param \Closure(FakeFeedHost): FakeFeedHost $host
     * @param class-string<\Throwable>             $expected
     */
    #[DataProvider('feedFailures')]
    public function testFeedUrlSecretInDownloadTrace(\Closure $host, string $expected): void
    {
        self::requireSensitiveParameter();
        $dir = sys_get_temp_dir() . '/gdeslon-trace-feed-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $gdeslon = new GdeSlon(new FakeHttpTransport(), feedDownloader: new RangedFeedDownloader($host(new FakeFeedHost(self::feedZip())), chunkSize: 1024, partRetries: 1, sleep: static function (int $microseconds): void {
        }));

        try {
            $e = self::catchWithTraceArgs(
                static fn () => $gdeslon->downloadFeed(FeedUrl::fromString('https://export.gdeslon.ru/uploads/exports/' . self::FEED_SECRET . '.zip'), $dir . '/feed.xml'),
                $expected,
            );
        } finally {
            array_map('unlink', array_filter(glob($dir . '/{,.}*', GLOB_BRACE) ?: [], 'is_file'));
            rmdir($dir);
        }

        self::assertTraceHidesSecrets($e, self::FEED_SECRET, self::ENTRY_SECRET);
        self::assertSerializedTraceHidesSecrets($e, self::FEED_SECRET, self::ENTRY_SECRET);
    }

    /**
     * @return iterable<string, array{\Closure(FakeHttpTransport): GdeSlon, \Closure(GdeSlon): mixed}>
     */
    public static function facadeCalls(): iterable
    {
        $clock = FrozenClock::at('2026-09-25 12:00:00');
        yield '_gs_at' => [static fn (FakeHttpTransport $t) => GdeSlon::create(new Config(apiToken: self::TOKEN), $t), static fn (GdeSlon $g) => $g->search('платье')];
        yield 'api_token' => [static fn (FakeHttpTransport $t) => GdeSlon::create(new Config(apiToken: self::TOKEN), $t), static fn (GdeSlon $g) => $g->coupons()];
        yield 'Bearer' => [static fn (FakeHttpTransport $t) => GdeSlon::create(new Config(apiToken: self::TOKEN), $t), static fn (GdeSlon $g) => $g->lostOrders()];
        yield 'Basic' => [static fn (FakeHttpTransport $t) => GdeSlon::create(new Config(userId: self::USER_ID, apiKey: self::API_KEY), $t, null, $clock), static fn (GdeSlon $g) => $g->orders()];
    }

    /**
     * @param \Closure(FakeHttpTransport): GdeSlon $create
     * @param \Closure(GdeSlon): mixed             $call
     */
    #[DataProvider('facadeCalls')]
    public function testFacadeExceptionsDoNotSerializeSecrets(\Closure $create, \Closure $call): void
    {
        $secrets = [self::TOKEN, self::API_KEY, base64_encode(self::USER_ID . ':' . self::API_KEY)];
        foreach ([401, 500] as $status) {
            $gdeslon = $create((new FakeHttpTransport())->willReturn($status, ''));

            $e = self::catchWithTraceArgs(static fn () => $call($gdeslon), HttpException::class);

            self::assertSerializedTraceHidesSecrets($e, ...$secrets);
        }
    }
}

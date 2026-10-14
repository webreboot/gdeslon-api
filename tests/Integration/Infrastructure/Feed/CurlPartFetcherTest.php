<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Integration\Infrastructure\Feed;

use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Exception\ConnectTimeoutException;
use Webreboot\GdeSlon\Exception\HttpException;
use Webreboot\GdeSlon\Exception\TimeoutException;
use Webreboot\GdeSlon\Exception\TransportException;
use Webreboot\GdeSlon\Infrastructure\Feed\CurlPartFetcher;
use Webreboot\GdeSlon\Infrastructure\Feed\FeedDownloadStatus;
use Webreboot\GdeSlon\Infrastructure\Feed\FeedUrl;
use Webreboot\GdeSlon\Infrastructure\Feed\PartResponse;
use Webreboot\GdeSlon\Infrastructure\Feed\RangedFeedDownloader;
use Webreboot\GdeSlon\Tests\Support\Fixtures;
use Webreboot\GdeSlon\Tests\Support\LocalHttpServer;
use Webreboot\GdeSlon\Tests\Support\SilentServer;
use Webreboot\GdeSlon\Tests\Support\StallingServer;
use Webreboot\GdeSlon\Tests\Support\ZipBuilder;

final class CurlPartFetcherTest extends TestCase
{
    private const SECRET = 'Zk3secretSecretSecret0123456789';

    private static ?LocalHttpServer $server = null;

    private string $dir;

    public static function setUpBeforeClass(): void
    {
        self::$server = LocalHttpServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/gdeslon-curl-feed-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', array_filter(glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [], 'is_file'));
        rmdir($this->dir);
    }

    public function testPartsAreFetchedInRequestedOrder(): void
    {
        $zip = self::zip();

        $results = (new CurlPartFetcher())->fetch(self::url('/feed/data.zip'), [[0, 1023], [1024, 2047], [4096, 4199]], []);

        self::assertCount(3, $results);
        foreach ([[0, 1023], [1024, 2047], [4096, 4199]] as $index => [$start, $end]) {
            $result = $results[$index];
            self::assertInstanceOf(PartResponse::class, $result);
            self::assertSame(206, $result->status());
            self::assertSame(sprintf('bytes %d-%d/%d', $start, $end, strlen($zip)), $result->header('Content-Range'));
            self::assertSame(substr($zip, $start, $end - $start + 1), $result->body());
            self::assertFalse($result->isTruncated());
            self::assertStringStartsWith('"feed-', (string) $result->header('etag'));
        }
    }

    public function testRequestHeaders(): void
    {
        $result = (new CurlPartFetcher(userAgent: 'test-agent/1'))->fetch(self::url('/feed/echo.zip'), [[0, 99999]], ['If-Range' => '"feed-1"'])[0];

        self::assertInstanceOf(PartResponse::class, $result);
        $headers = json_decode($result->body(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($headers);
        self::assertSame('bytes=0-99999', $headers['range']);
        self::assertSame('close', $headers['connection']);
        self::assertSame('"feed-1"', $headers['if-range']);
        self::assertSame('test-agent/1', $headers['user-agent']);
        self::assertArrayNotHasKey('accept-encoding', $headers);
    }

    public function testConditionalRangeRequestGets304(): void
    {
        $etag = (new CurlPartFetcher())->fetch(self::url('/feed/data.zip'), [[0, 99]], [])[0];
        self::assertInstanceOf(PartResponse::class, $etag);

        $result = (new CurlPartFetcher())->fetch(self::url('/feed/data.zip'), [[0, 99]], ['If-None-Match' => (string) $etag->header('etag')])[0];

        self::assertInstanceOf(PartResponse::class, $result);
        self::assertSame(304, $result->status());
    }

    public function testFullBodyBeyondPartIsTruncated(): void
    {
        $result = (new CurlPartFetcher())->fetch(self::url('/feed/norange.zip'), [[0, 1023]], [])[0];

        self::assertInstanceOf(PartResponse::class, $result);
        self::assertSame(200, $result->status());
        self::assertTrue($result->isTruncated());
        self::assertSame(substr(self::zip(), 0, 1024), $result->body());
    }

    public function testClosedPortIsTransportErrorWithoutSecret(): void
    {
        $port = LocalHttpServer::freePort();

        $result = (new CurlPartFetcher(connectTimeout: 2.0))->fetch(FeedUrl::fromString('http://127.0.0.1:' . $port . '/feed/' . self::SECRET . '.zip'), [[0, 99]], [])[0];

        self::assertInstanceOf(TransportException::class, $result);
        self::assertSame('http://127.0.0.1:' . $port . '/feed/***', $result->url());
        self::assertStringNotContainsString(self::SECRET, $result->getMessage());
    }

    public function testTlsHangIsConnectTimeout(): void
    {
        $server = SilentServer::start();
        try {
            $url = FeedUrl::fromString($server->url('https') . 'feed/' . self::SECRET . '.zip');
            $result = (new CurlPartFetcher(timeout: 5.0, connectTimeout: 0.3))->fetch($url, [[0, 99]], [])[0];
        } finally {
            $server->stop();
        }

        self::assertInstanceOf(ConnectTimeoutException::class, $result);
        self::assertStringNotContainsString(self::SECRET, $result->getMessage());
    }

    public function testStalledBodyIsReadTimeout(): void
    {
        $server = StallingServer::partialBody();
        try {
            $result = (new CurlPartFetcher(timeout: 0.5, connectTimeout: 2.0))->fetch(FeedUrl::fromString($server->url('/feed/' . self::SECRET . '.zip')), [[0, 99999]], [])[0];
        } finally {
            $server->stop();
        }

        self::assertInstanceOf(TimeoutException::class, $result);
        self::assertNotInstanceOf(ConnectTimeoutException::class, $result);
        self::assertStringNotContainsString(self::SECRET, $result->getMessage());
    }

    public function testStalledBodyTraceDoesNotHoldReceivedBytes(): void
    {
        if (PHP_VERSION_ID < 80200) {
            self::markTestSkipped('#[\SensitiveParameter] действует с PHP 8.2');
        }
        $server = StallingServer::partialBody();
        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');
        try {
            $result = (new CurlPartFetcher(timeout: 0.5, connectTimeout: 2.0))->fetch(FeedUrl::fromString($server->url('/feed/' . self::SECRET . '.zip')), [[0, 99999]], [])[0];
        } finally {
            $server->stop();
            if ($ignoreArgs !== false) {
                ini_set('zend.exception_ignore_args', $ignoreArgs);
            }
        }

        self::assertInstanceOf(TimeoutException::class, $result);
        $trace = var_export($result->getTrace(), true) . print_r($result->getTrace(), true);
        self::assertStringNotContainsString(self::SECRET, $trace);
        self::assertStringNotContainsString(str_repeat('x', 200), $trace, 'полученные байты тела в трассе');
    }

    public function testDownloaderEndToEndAndNotModified(): void
    {
        $downloader = new RangedFeedDownloader(new CurlPartFetcher(), chunkSize: 4096, concurrency: 4);
        $target = $this->dir . '/women.xml';

        $download = $downloader->download(self::url('/feed/data.zip'), $target);

        self::assertSame(FeedDownloadStatus::Downloaded, $download->status());
        self::assertSame(Fixtures::read('feeds/feed.xml'), file_get_contents($target));
        self::assertSame(['women.xml'], array_values(array_map('basename', array_filter(glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [], 'is_file'))));

        $again = $downloader->download(self::url('/feed/data.zip'), $target, $download->validators());

        self::assertSame(FeedDownloadStatus::NotModified, $again->status());
    }

    public function testDownloaderReportsMissingFeedWithoutSecret(): void
    {
        $downloader = new RangedFeedDownloader(new CurlPartFetcher(), chunkSize: 4096);

        try {
            $downloader->download(self::url('/feed/missing-' . self::SECRET . '.zip'), $this->dir . '/women.xml');
            self::fail('ожидалось исключение');
        } catch (HttpException $e) {
            self::assertSame(404, $e->statusCode());
            self::assertStringNotContainsString(self::SECRET, $e->getMessage() . $e->url() . $e->responseSnippet());
        }
    }

    private static function url(string $path): FeedUrl
    {
        self::assertNotNull(self::$server);

        return FeedUrl::fromString(self::$server->url($path));
    }

    private static function zip(): string
    {
        return (new ZipBuilder())->add('a1b2c3secret.xml', Fixtures::read('feeds/feed.xml'), ZipBuilder::STORED)->build();
    }
}

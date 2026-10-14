<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Infrastructure\Feed;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Exception\AuthenticationException;
use Webreboot\GdeSlon\Exception\ConnectTimeoutException;
use Webreboot\GdeSlon\Exception\FileException;
use Webreboot\GdeSlon\Exception\HttpException;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Exception\TimeoutException;
use Webreboot\GdeSlon\Exception\TransportException;
use Webreboot\GdeSlon\Exception\UnexpectedResponseException;
use Webreboot\GdeSlon\Exception\UnsupportedFormatException;
use Webreboot\GdeSlon\Infrastructure\Feed\FeedDownloadStatus;
use Webreboot\GdeSlon\Infrastructure\Feed\FeedUrl;
use Webreboot\GdeSlon\Infrastructure\Feed\FeedValidators;
use Webreboot\GdeSlon\Infrastructure\Feed\PartResponse;
use Webreboot\GdeSlon\Infrastructure\Feed\RangedFeedDownloader;
use Webreboot\GdeSlon\Infrastructure\Yml\ZipEntryExtractor;
use Webreboot\GdeSlon\Tests\Support\FakeFeedHost;
use Webreboot\GdeSlon\Tests\Support\Fixtures;
use Webreboot\GdeSlon\Tests\Support\ZipBuilder;

final class RangedFeedDownloaderTest extends TestCase
{
    private const CHUNK = 1024;

    private const SECRET = 'Zk3secretSecretSecret0123456789abcdef';

    private string $dir;

    private FeedUrl $url;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/gdeslon-download-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->url = FeedUrl::fromString('https://export.gdeslon.ru/uploads/exports/' . self::SECRET . '.zip');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->dir);
    }

    public function testZipIsDownloadedInOrderedPartsAndUnpacked(): void
    {
        $host = new FakeFeedHost(self::zip());

        $download = $this->downloader($host)->download($this->url, $this->target());

        self::assertSame(FeedDownloadStatus::Downloaded, $download->status());
        self::assertFalse($download->isNotModified());
        self::assertSame($this->target(), $download->path());
        self::assertSame(Fixtures::read('feeds/feed.xml'), file_get_contents($this->target()));
        self::assertSame(strlen(Fixtures::read('feeds/feed.xml')), $download->bytes());
        self::assertSame(['etag' => '"6acf4fde-272ead2"', 'last_modified' => FakeFeedHost::LAST_MODIFIED], $download->validators()->toArray());
        self::assertSame(['women.xml'], self::files($this->dir));

        $requests = $host->requests();
        self::assertSame(intdiv(strlen(self::zip()) - 1, self::CHUNK) + 1, count($requests));
        self::assertSame([0, self::CHUNK - 1], $requests[0]['range']);
        self::assertArrayNotHasKey('If-None-Match', $requests[0]['headers']);
        self::assertArrayNotHasKey('If-Range', $requests[0]['headers']);
        foreach (array_slice($requests, 1) as $request) {
            self::assertSame('"6acf4fde-272ead2"', $request['headers']['If-Range']);
        }
        self::assertSame(array_map(static fn (int $i): int => $i * self::CHUNK, array_keys($requests)), array_map(static fn (array $r): int => $r['range'][0], $requests));
        $batches = $host->batches();
        self::assertNotEmpty($batches);
        self::assertLessThanOrEqual(3, max($batches));
        self::assertGreaterThan(1, max($batches));
    }

    public function testPlainXmlIsMovedToTarget(): void
    {
        $xml = Fixtures::read('feeds/feed.xml');

        $download = $this->downloader(new FakeFeedHost($xml))->download($this->url, $this->target());

        self::assertSame($xml, file_get_contents($this->target()));
        self::assertSame(['women.xml'], self::files($this->dir));
        self::assertSame(strlen($xml), $download->bytes());
    }

    public function testSmallFileFitsInFirstPart(): void
    {
        $xml = '<?xml version="1.0"?><yml_catalog/>';
        $host = new FakeFeedHost($xml);

        $this->downloader($host)->download($this->url, $this->target());

        self::assertSame($xml, file_get_contents($this->target()));
        self::assertCount(1, $host->requests());
    }

    public function testNotModifiedWhenTargetExistsAndValidatorsMatch(): void
    {
        file_put_contents($this->target(), 'старая выгрузка');
        $host = new FakeFeedHost(self::zip());
        $previous = new FeedValidators('"6acf4fde-272ead2"', FakeFeedHost::LAST_MODIFIED);

        $download = $this->downloader($host)->download($this->url, $this->target(), $previous);

        self::assertSame(FeedDownloadStatus::NotModified, $download->status());
        self::assertTrue($download->isNotModified());
        self::assertSame('старая выгрузка', file_get_contents($this->target()));
        self::assertSame(strlen('старая выгрузка'), $download->bytes());
        self::assertSame($previous->toArray(), $download->validators()->toArray());
        self::assertCount(1, $host->requests());
        self::assertSame('"6acf4fde-272ead2"', $host->requests()[0]['headers']['If-None-Match']);
        self::assertSame(FakeFeedHost::LAST_MODIFIED, $host->requests()[0]['headers']['If-Modified-Since']);
    }

    public function testChangedFeedIsDownloadedDespiteOldValidators(): void
    {
        file_put_contents($this->target(), 'старая выгрузка');
        $previous = new FeedValidators('"old"', 'Tue, 13 Oct 2026 18:50:49 GMT');

        $download = $this->downloader(new FakeFeedHost(self::zip()))->download($this->url, $this->target(), $previous);

        self::assertSame(FeedDownloadStatus::Downloaded, $download->status());
        self::assertSame(Fixtures::read('feeds/feed.xml'), file_get_contents($this->target()));
    }

    public function testValidatorsWithoutTargetAreNotSent(): void
    {
        $host = new FakeFeedHost(self::zip());

        $this->downloader($host)->download($this->url, $this->target(), new FeedValidators('"6acf4fde-272ead2"', FakeFeedHost::LAST_MODIFIED));

        self::assertArrayNotHasKey('If-None-Match', $host->requests()[0]['headers']);
        self::assertArrayNotHasKey('If-Modified-Since', $host->requests()[0]['headers']);
        self::assertFileExists($this->target());
    }

    /**
     * @return iterable<string, array{\Closure(): (\Webreboot\GdeSlon\Exception\TransportException|int)}>
     */
    public static function transientFailures(): iterable
    {
        yield 'таймаут' => [static fn (): TimeoutException => FakeFeedHost::timeout()];
        yield '503' => [static fn (): int => 503];
        yield '420 лимит' => [static fn (): int => 420];
    }

    /**
     * @param \Closure(): (\Webreboot\GdeSlon\Exception\TransportException|int) $failure
     */
    #[DataProvider('transientFailures')]
    public function testTransientPartFailureIsRetried(\Closure $failure): void
    {
        $host = (new FakeFeedHost(self::zip()))->failAt(5 * self::CHUNK, $failure(), 2)->failAt(0, $failure());
        $sleeps = [];

        $this->downloader($host, sleeps: $sleeps)->download($this->url, $this->target());

        self::assertSame(Fixtures::read('feeds/feed.xml'), file_get_contents($this->target()));
        self::assertCount(3, $sleeps);
    }

    public function testNonNetworkCurlErrorIsNotRetried(): void
    {
        $tls = new TransportException('GET https://export.gdeslon.ru/uploads/exports/***: ошибка соединения (cURL 60: SSL)', 'GET', 'https://export.gdeslon.ru/uploads/exports/***', 60);
        $host = (new FakeFeedHost(self::zip()))->failAt(0, $tls);
        $sleeps = [];

        try {
            $this->downloader($host, sleeps: $sleeps)->download($this->url, $this->target());
            self::fail('ожидалось исключение');
        } catch (TransportException $e) {
            self::assertSame($tls, $e);
        }
        self::assertCount(1, $host->requests());
        self::assertSame([], $sleeps);
    }

    public function testTransientTlsAndDnsFailuresAreRetried(): void
    {
        $url = 'https://export.gdeslon.ru/uploads/exports/***';
        $host = (new FakeFeedHost(self::zip()))
            ->failAt(0, new TransportException('GET ' . $url . ': ошибка соединения (cURL 35)', 'GET', $url, 35))
            ->failAt(2 * self::CHUNK, new TransportException('GET ' . $url . ': ошибка соединения (cURL 6)', 'GET', $url, 6));

        $this->downloader($host)->download($this->url, $this->target());

        self::assertSame(Fixtures::read('feeds/feed.xml'), file_get_contents($this->target()));
    }

    public function testStoredArchiveAndXmlDownloadWithoutZlib(): void
    {
        $downloader = new RangedFeedDownloader(new FakeFeedHost(self::zip()), chunkSize: self::CHUNK, extractor: new ZipEntryExtractor(zlibAvailable: false));
        $downloader->download($this->url, $this->target());
        self::assertSame(Fixtures::read('feeds/feed.xml'), file_get_contents($this->target()));

        $xml = Fixtures::read('feeds/feed.xml');
        $downloader = new RangedFeedDownloader(new FakeFeedHost($xml), chunkSize: self::CHUNK, extractor: new ZipEntryExtractor(zlibAvailable: false));
        $downloader->download($this->url, $this->dir . '/plain.xml');
        self::assertSame($xml, file_get_contents($this->dir . '/plain.xml'));
    }

    public function testConnectionFailuresAreRetried(): void
    {
        $refused = new TransportException('GET https://export.gdeslon.ru/uploads/exports/***: ошибка соединения (cURL 7)', 'GET', 'https://export.gdeslon.ru/uploads/exports/***', 7);
        $host = (new FakeFeedHost(self::zip()))->failAt(0, $refused)->failAt(3 * self::CHUNK, new ConnectTimeoutException('таймаут соединения', 'GET', 'https://export.gdeslon.ru/uploads/exports/***', 28));

        $this->downloader($host)->download($this->url, $this->target());

        self::assertSame(Fixtures::read('feeds/feed.xml'), file_get_contents($this->target()));
    }

    public function testDeflateArchiveWithoutZlibFailsBeforeDownloadingRest(): void
    {
        $zip = (new ZipBuilder())->add('feed.xml', str_repeat(Fixtures::read('feeds/feed.xml'), 3))->build();
        $host = new FakeFeedHost($zip);
        $downloader = new RangedFeedDownloader($host, chunkSize: self::CHUNK, extractor: new ZipEntryExtractor(zlibAvailable: false));

        try {
            $downloader->download($this->url, $this->target());
            self::fail('ожидалось исключение');
        } catch (UnsupportedFormatException $e) {
            self::assertStringContainsString('zlib', $e->getMessage());
        }
        self::assertCount(1, $host->requests());
        self::assertSame([], self::files($this->dir));
    }

    public function testConcurrentDownloadOfSameTargetIsRefused(): void
    {
        file_put_contents($this->target() . '.zip.part', 'чужая загрузка');
        $lock = fopen($this->target() . '.zip.part.lock', 'c');
        self::assertNotFalse($lock);
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        $host = new FakeFeedHost(self::zip());

        try {
            $this->downloader($host)->download($this->url, $this->target());
            self::fail('ожидалось исключение');
        } catch (FileException $e) {
            self::assertStringContainsString('уже загружается', $e->getMessage());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        self::assertSame([], $host->requests());
        self::assertSame('чужая загрузка', file_get_contents($this->target() . '.zip.part'));

        $this->downloader(new FakeFeedHost(self::zip()))->download($this->url, $this->target());
        self::assertSame(['women.xml'], self::files($this->dir));
    }

    public function test416MidDownloadMeansFeedChanged(): void
    {
        $zip = self::zip();
        $host = (new FakeFeedHost($zip))->override(4, new PartResponse(416, ['Content-Range' => 'bytes */100'], ''));

        $this->downloader($host)->download($this->url, $this->target());

        self::assertSame(Fixtures::read('feeds/feed.xml'), file_get_contents($this->target()));
        self::assertSame([0, self::CHUNK - 1], $host->requests()[4]['range'] ?? null, 'после 416 — загрузка заново с первой части');
    }

    public function testOtherTotalMidDownloadMeansFeedChanged(): void
    {
        $zip = self::zip();
        $host = (new FakeFeedHost($zip))->override(3, new PartResponse(206, ['ETag' => '"6acf4fde-272ead2"', 'Content-Range' => sprintf('bytes 2048-3071/%d', strlen($zip) + 5)], substr($zip, 2048, self::CHUNK)));

        $this->downloader($host)->download($this->url, $this->target());

        self::assertSame(Fixtures::read('feeds/feed.xml'), file_get_contents($this->target()));
        self::assertSame([0, self::CHUNK - 1], $host->requests()[4]['range'] ?? null);
    }

    public function testWeakEtagUsesLastModifiedForIfRange(): void
    {
        $host = new FakeFeedHost(self::zip(), 'W/"weak"');

        $this->downloader($host)->download($this->url, $this->target());

        self::assertSame(FakeFeedHost::LAST_MODIFIED, $host->requests()[1]['headers']['If-Range']);
    }

    public function testHostWithoutValidatorsDownloadsWithoutResumeState(): void
    {
        $zip = self::zip();
        $host = (new FakeFeedHost($zip, '', lastModified: false))->failAt(5 * self::CHUNK, FakeFeedHost::timeout(), 10);

        try {
            $this->downloader($host)->download($this->url, $this->target());
            self::fail('ожидалось исключение');
        } catch (TimeoutException) {
        }
        self::assertArrayNotHasKey('If-Range', $host->requests()[1]['headers']);
        self::assertSame(['women.xml.zip.part'], self::files($this->dir));

        $again = new FakeFeedHost($zip, '', lastModified: false);
        $download = $this->downloader($again)->download($this->url, $this->target());

        self::assertSame(self::CHUNK, $again->requests()[1]['range'][0], 'без валидаторов докачки нет');
        self::assertTrue($download->validators()->isEmpty());
        self::assertSame(['women.xml'], self::files($this->dir));
    }

    public function testExhaustedRetriesKeepContiguousPartForResume(): void
    {
        $zip = self::zip();
        $host = (new FakeFeedHost($zip))->failAt(7 * self::CHUNK, FakeFeedHost::timeout(), 4);

        try {
            $this->downloader($host)->download($this->url, $this->target());
            self::fail('ожидалось исключение');
        } catch (TimeoutException $e) {
            self::assertStringNotContainsString(self::SECRET, $e->getMessage());
        }

        self::assertFileDoesNotExist($this->target());
        self::assertSame(['women.xml.zip.part', 'women.xml.zip.part.json'], self::files($this->dir));
        self::assertSame(substr($zip, 0, 7 * self::CHUNK), file_get_contents($this->target() . '.zip.part'));
        self::assertStringNotContainsString(self::SECRET, (string) file_get_contents($this->target() . '.zip.part.json'));

        $resumed = new FakeFeedHost($zip);
        $this->downloader($resumed)->download($this->url, $this->target());

        self::assertSame(Fixtures::read('feeds/feed.xml'), file_get_contents($this->target()));
        self::assertSame(['women.xml'], self::files($this->dir));
        self::assertSame([0, self::CHUNK - 1], $resumed->requests()[0]['range']);
        self::assertSame(7 * self::CHUNK, $resumed->requests()[1]['range'][0]);
    }

    public function testStalePartOfAnotherVersionIsDiscarded(): void
    {
        $zip = self::zip();
        file_put_contents($this->target() . '.zip.part', str_repeat('x', 5 * self::CHUNK));
        file_put_contents($this->target() . '.zip.part.json', json_encode(['etag' => '"old"', 'last_modified' => null, 'total' => strlen($zip)]));
        $host = new FakeFeedHost($zip);

        $this->downloader($host)->download($this->url, $this->target());

        self::assertSame(Fixtures::read('feeds/feed.xml'), file_get_contents($this->target()));
        self::assertSame(self::CHUNK, $host->requests()[1]['range'][0]);
    }

    public function testCorruptStateFileIsIgnored(): void
    {
        file_put_contents($this->target() . '.zip.part', 'мусор');
        file_put_contents($this->target() . '.zip.part.json', '{не json');

        $this->downloader(new FakeFeedHost(self::zip()))->download($this->url, $this->target());

        self::assertSame(Fixtures::read('feeds/feed.xml'), file_get_contents($this->target()));
        self::assertSame(['women.xml'], self::files($this->dir));
    }

    public function testFeedChangedDuringDownloadRestartsOnce(): void
    {
        $other = (new ZipBuilder())->add('other.xml', str_replace('2026-10-13 21:50', '2026-10-14 12:48', Fixtures::read('feeds/feed.xml')), ZipBuilder::STORED)->build();
        $host = (new FakeFeedHost(self::zip()))->changeAfter(10, $other, '"new"');

        $download = $this->downloader($host)->download($this->url, $this->target());

        self::assertStringContainsString('2026-10-14 12:48', (string) file_get_contents($this->target()));
        self::assertSame('"new"', $download->validators()->etag());
        self::assertSame(['women.xml'], self::files($this->dir));
    }

    public function testFeedChangingAgainAfterRestartFails(): void
    {
        $zip = self::zip();
        $host = (new FakeFeedHost($zip))->changeAfter(5, $zip, '"v2"');
        $host->override(20, new PartResponse(206, ['ETag' => '"v3"', 'Content-Range' => 'bytes 5120-6143/' . strlen($zip)], str_repeat('x', self::CHUNK)));

        try {
            $this->downloader($host)->download($this->url, $this->target());
            self::fail('ожидалось исключение');
        } catch (UnexpectedResponseException $e) {
            self::assertStringContainsString('выгрузка менялась во время загрузки', $e->getMessage());
        }
        self::assertSame([], self::files($this->dir));
    }

    public function testWrongContentRangeIsRejected(): void
    {
        $zip = self::zip();
        $host = (new FakeFeedHost($zip))->override(3, new PartResponse(206, ['ETag' => '"6acf4fde-272ead2"', 'Content-Range' => 'bytes 0-1023/' . strlen($zip)], substr($zip, 0, self::CHUNK)));

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('Content-Range');

        $this->downloader($host)->download($this->url, $this->target());
    }

    public function testShortPartBodyIsRetried(): void
    {
        $zip = self::zip();
        $host = (new FakeFeedHost($zip))->override(3, new PartResponse(206, ['ETag' => '"6acf4fde-272ead2"', 'Content-Range' => sprintf('bytes 2048-3071/%d', strlen($zip))], 'обрыв'));

        $this->downloader($host)->download($this->url, $this->target());

        self::assertSame(Fixtures::read('feeds/feed.xml'), file_get_contents($this->target()));
    }

    /**
     * @return iterable<string, array{int, class-string<\Throwable>}>
     */
    public static function httpErrors(): iterable
    {
        yield '404 неверный секрет' => [404, HttpException::class];
        yield '403' => [403, AuthenticationException::class];
        yield '401' => [401, AuthenticationException::class];
        yield '500 после повторов' => [500, HttpException::class];
    }

    /**
     * @param class-string<\Throwable> $exception
     */
    #[DataProvider('httpErrors')]
    public function testHttpErrorsLeaveTargetUntouched(int $status, string $exception): void
    {
        file_put_contents($this->target(), 'старая выгрузка');
        $host = (new FakeFeedHost(self::zip()))->failAt(0, $status, 10);

        try {
            $this->downloader($host)->download($this->url, $this->target());
            self::fail('ожидалось исключение');
        } catch (HttpException $e) {
            self::assertInstanceOf($exception, $e);
            self::assertSame($status, $e->statusCode());
            self::assertSame('https://export.gdeslon.ru/uploads/exports/***', $e->url());
            self::assertStringNotContainsString(self::SECRET, $e->getMessage() . $e->responseSnippet());
        }
        self::assertSame('старая выгрузка', file_get_contents($this->target()));
        self::assertSame(['women.xml'], self::files($this->dir));
    }

    public function testHtmlInsteadOfFeedIsRejected(): void
    {
        $host = new FakeFeedHost('<!DOCTYPE html><html><body>Войти ' . self::SECRET . '</body></html>');

        try {
            $this->downloader($host)->download($this->url, $this->target());
            self::fail('ожидалось исключение');
        } catch (UnexpectedResponseException $e) {
            self::assertStringContainsString('не выгрузка', $e->getMessage());
            self::assertStringNotContainsString(self::SECRET, $e->getMessage());
        }
        self::assertSame([], self::files($this->dir));
    }

    public function testHostIgnoringRangeForBigFileIsRejected(): void
    {
        $host = (new FakeFeedHost(self::zip(), limit: self::CHUNK))->ignoreRange();

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('не поддерживает Range');

        $this->downloader($host)->download($this->url, $this->target());
    }

    public function testHostIgnoringRangeForSmallFileIsAccepted(): void
    {
        $xml = '<?xml version="1.0"?><yml_catalog/>';

        $this->downloader((new FakeFeedHost($xml))->ignoreRange())->download($this->url, $this->target());

        self::assertSame($xml, file_get_contents($this->target()));
    }

    public function testTooBigFeedIsRejectedBeforeWriting(): void
    {
        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('больше лимита 5000 байт');

        try {
            $this->downloader(new FakeFeedHost(self::zip()), maxBytes: 5000)->download($this->url, $this->target());
        } finally {
            self::assertSame([], self::files($this->dir));
        }
    }

    public function testCorruptArchiveIsDiscarded(): void
    {
        $zip = (new ZipBuilder())->add('feed.xml', Fixtures::read('feeds/feed.xml'), ZipBuilder::STORED, crc: 1)->build();

        try {
            $this->downloader(new FakeFeedHost($zip))->download($this->url, $this->target());
            self::fail('ожидалось исключение');
        } catch (UnexpectedResponseException $e) {
            self::assertStringContainsString('контрольная сумма', $e->getMessage());
        }
        self::assertSame([], self::files($this->dir));
    }

    public function testInvalidTargets(): void
    {
        try {
            $this->downloader(new FakeFeedHost(self::zip()))->download($this->url, 'php://memory');
            self::fail('ожидалось исключение');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(FileException::class);
        $this->downloader(new FakeFeedHost(self::zip()))->download($this->url, $this->dir . '/missing/women.xml');
    }

    public function testInvalidSettingsRejected(): void
    {
        $host = new FakeFeedHost('');
        foreach ([['chunkSize' => 100], ['concurrency' => 0], ['concurrency' => 65], ['partRetries' => -1], ['maxBytes' => 0]] as $arguments) {
            try {
                new RangedFeedDownloader($host, ...$arguments);
                self::fail('ожидалось исключение: ' . json_encode($arguments));
            } catch (InvalidArgumentException) {
            }
        }
        $this->addToAssertionCount(5);
    }

    /**
     * @param list<int> $sleeps
     */
    private function downloader(FakeFeedHost $host, int $maxBytes = RangedFeedDownloader::DEFAULT_MAX_BYTES, array &$sleeps = []): RangedFeedDownloader
    {
        return new RangedFeedDownloader(
            $host,
            chunkSize: self::CHUNK,
            concurrency: 3,
            partRetries: 3,
            maxBytes: $maxBytes,
            sleep: static function (int $microseconds) use (&$sleeps): void {
                $sleeps[] = $microseconds;
            },
        );
    }

    private function target(): string
    {
        return $this->dir . '/women.xml';
    }

    private static function zip(): string
    {
        return (new ZipBuilder())->add('a1b2c3secret.xml', Fixtures::read('feeds/feed.xml'), ZipBuilder::STORED)->build();
    }

    /**
     * @return list<string>
     */
    private static function files(string $dir): array
    {
        $files = array_values(array_filter(
            array_map('basename', glob($dir . '/{,.}*', GLOB_BRACE) ?: []),
            static fn (string $f): bool => !in_array($f, ['.', '..'], true),
        ));
        sort($files);

        return $files;
    }
}

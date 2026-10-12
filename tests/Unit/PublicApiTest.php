<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Config;
use Webreboot\GdeSlon\Domain\Shared\Clock;
use Webreboot\GdeSlon\Infrastructure\Clock\SystemClock;
use Webreboot\GdeSlon\Infrastructure\Http\HttpTransport;
use Webreboot\GdeSlon\Infrastructure\Http\TransportFactory;

final class PublicApiTest extends TestCase
{
    public function testTransportFactoryAndSystemClockArePublic(): void
    {
        foreach ([TransportFactory::class, SystemClock::class] as $class) {
            $reflection = new \ReflectionClass($class);
            self::assertStringNotContainsString('@internal', (string) $reflection->getDocComment(), $class);
            self::assertTrue($reflection->isFinal(), $class);
        }
        self::assertTrue((new \ReflectionClass(SystemClock::class))->implementsInterface(Clock::class));

        $method = new \ReflectionMethod(TransportFactory::class, 'fromConfig');
        self::assertTrue($method->isPublic() && $method->isStatic());
        self::assertStringNotContainsString('@internal', (string) $method->getDocComment());
        self::assertSame(HttpTransport::class, (string) $method->getReturnType());

        $parameters = $method->getParameters();
        self::assertSame(['config', 'inner', 'rangeHosts'], array_map(static fn (\ReflectionParameter $p): string => $p->name, $parameters));
        self::assertSame(Config::class, (string) $parameters[0]->getType());
        self::assertNotSame([], $parameters[0]->getAttributes(\SensitiveParameter::class));
        self::assertSame('?' . HttpTransport::class, (string) $parameters[1]->getType());
        self::assertNull($parameters[1]->getDefaultValue());
        self::assertSame('?array', (string) $parameters[2]->getType());
        self::assertNull($parameters[2]->getDefaultValue());
    }

    public function testReadmeDocumentsTransportDecoration(): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__, 2) . '/README.md');
        $transport = self::section($readme, '### Собственный транспорт');
        foreach ([
            'GdeSlon::create(transport: $transport)',
            'new RecordingTransport(TransportFactory::fromConfig($config), $dir)',
            'TransportFactory::fromConfig($config, inner: new MetricsTransport(CurlTransport::fromConfig($config)))',
            'public function send(#[\SensitiveParameter] HttpRequest $request): HttpResponse',
            'maskedUri()',
            'персональные данные',
            'не кэширует',
            'не подгоняется',
        ] as $expected) {
            self::assertStringContainsString($expected, $transport);
        }
        self::assertStringNotContainsString("getenv('GDESLON_TOKEN')", $transport);
        self::assertStringContainsString("getenv('GDESLON_API_TOKEN') ?: null", $transport);

        $compatibility = self::section($readme, '## Совместимость');
        self::assertStringContainsString('`TransportFactory`', $compatibility);
        self::assertStringContainsString('`SystemClock`', $compatibility);

        $release = self::section((string) file_get_contents(dirname(__DIR__, 2) . '/CHANGELOG.md'), '## [0.2.0] — 2026-10-11');
        self::assertStringContainsString('`TransportFactory`', $release);
        self::assertStringContainsString('`SystemClock`', $release);
        self::assertStringContainsString('inner:', $release);
        self::assertStringContainsString('rangeHosts', $release);
    }

    public function testReadmeDocumentsTesting(): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__, 2) . '/README.md');
        $testing = self::section($readme, '## Тестирование в приложении');
        foreach ([
            'use Webreboot\GdeSlon\Testing\FakeHttpTransport;',
            '(new FakeHttpTransport())->willReturn(200, ',
            "new Config(apiToken: 'test-token')",
            '->willFail(28',
            'searchFallbackLimit: null',
            'FrozenClock::at(',
            '->advance(',
            'new InMemoryCacheStore()',
            'new GdeSlon($transport, categories: new FakeCategoryRepository($tree))',
            '`\LogicException`',
            '`GdeSlonException`',
            'обезлич',
            'фиктивный токен',
            'отбор по магазину, состоянию и статусу; период не учитывается',
            'следующий свободный номер от 9001',
        ] as $expected) {
            self::assertStringContainsString($expected, $testing);
        }
        self::assertDoesNotMatchRegularExpression('~(?<!\()new FakeHttpTransport\(\)->~', $testing, 'синтаксис PHP 8.4 без скобок');

        $compatibility = self::section($readme, '## Совместимость');
        self::assertStringContainsString('`Testing\*`', $compatibility);
        self::assertStringContainsString('`\LogicException`', $compatibility);
        self::assertStringContainsString('Testing/', self::section($readme, '## Архитектура'));
        self::assertStringContainsString('src/Testing', self::section($readme, '## Разработка'));

        $release = self::section((string) file_get_contents(dirname(__DIR__, 2) . '/CHANGELOG.md'), '## [0.2.0] — 2026-10-11');
        self::assertStringContainsString('`Webreboot\GdeSlon\Testing`', $release);
        foreach (['FakeHttpTransport', 'FrozenClock', 'InMemoryCacheStore', 'FakeLostOrderClaims'] as $class) {
            self::assertStringContainsString($class, $release);
        }
    }

    public function testReadmeDocumentsConnectTimeout(): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__, 2) . '/README.md');

        self::assertStringContainsString('`ConnectTimeoutException`', self::section($readme, '## Конфигурация'));
        self::assertStringContainsString('`ConnectTimeoutException`', self::section($readme, '#### Повтор меньшими страницами'));
        self::assertStringContainsString('| `ConnectTimeoutException` |', self::section($readme, '## Исключения'));
        self::assertStringContainsString('`ConnectTimeoutException`', self::section($readme, '### Загрузка частями'));
        $transport = self::section($readme, '### Собственный транспорт');
        foreach (['`ConnectTimeoutException`', '`TimeoutException`', 'ConnectException', 'старых libcurl (проверено на 7.74; на 8.14 и новее — различается)'] as $expected) {
            self::assertStringContainsString($expected, $transport);
        }
        $testing = self::section($readme, '## Тестирование в приложении');
        self::assertStringContainsString('->willTimeOutOnConnect()', $testing);
        self::assertStringContainsString('`willTimeOutOnConnect()`', $testing);
        self::assertStringNotContainsString('тоже запускает повтор', $readme);
        self::assertStringNotContainsString('срабатывает только на `TimeoutException`', $readme);
        self::assertStringNotContainsString('libcurl 7', $readme);

        $unreleased = self::section((string) file_get_contents(dirname(__DIR__, 2) . '/CHANGELOG.md'), '## [0.3.0] — 2026-10-11');
        foreach (['### Добавлено', '### Изменено', '`ConnectTimeoutException`', '`FakeHttpTransport::willTimeOutOnConnect()`', 'старых libcurl (проверено на 7.74; на 8.14 и новее — различается)'] as $expected) {
            self::assertStringContainsString($expected, $unreleased);
        }
    }

    public function testReadmeDocumentsSplitAndConnectRetries(): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__, 2) . '/README.md');

        self::assertStringContainsString('| `connectRetries` |', self::section($readme, '## Конфигурация'));
        $fallback = self::section($readme, '#### Повтор меньшими страницами');
        foreach (['наименьший размер', '`searchFallbackLimit: 2`', 'не хватает под-запросов', '`connectRetries`'] as $expected) {
            self::assertStringContainsString($expected, $fallback);
        }
        self::assertStringNotContainsString('(запрос k из n)', $fallback);
        $transport = self::section($readme, '### Собственный транспорт');
        self::assertStringContainsString('`RetryOnConnectTimeoutTransport`', $transport);
        self::assertStringNotContainsString('он возвращается как есть', $transport);
        $range = self::section($readme, '### Загрузка частями');
        foreach (['keep-alive', '`connectRetries`', 'с начала'] as $expected) {
            self::assertStringContainsString($expected, $range);
        }
        self::assertStringNotContainsString('первая часть не повторяется', $range);

        $unreleased = self::section((string) file_get_contents(dirname(__DIR__, 2) . '/CHANGELOG.md'), '## [Не выпущено]');
        foreach (['### Добавлено', '### Изменено', '`Config::connectRetries`', '`RetryOnConnectTimeoutTransport`', '`SearchResult::effectiveLimit()`', '### Переход с 0.3', '`connectRetries: 0`', '`searchFallbackLimit: null`'] as $expected) {
            self::assertStringContainsString($expected, $unreleased);
        }
    }

    public function testReadmeDocumentsReachableCategories(): void
    {
        $categories = self::section((string) file_get_contents(dirname(__DIR__, 2) . '/README.md'), '### Категории');
        foreach (['isReachable(', 'reachableOnly: true', '`CategoryNotFoundException`', '`[]`'] as $expected) {
            self::assertStringContainsString($expected, $categories);
        }

        $unreleased = self::section((string) file_get_contents(dirname(__DIR__, 2) . '/CHANGELOG.md'), '## [Не выпущено]');
        self::assertStringContainsString('`CategoryTree::isReachable()`', $unreleased);
        self::assertStringContainsString('reachableOnly: true', $unreleased);
    }

    private static function section(string $markdown, string $heading): string
    {
        $start = strpos($markdown, "\n" . $heading . "\n");
        self::assertNotFalse($start, 'Нет раздела ' . $heading);
        $level = strlen((string) strstr($heading, ' ', true));
        $rest = substr($markdown, $start + strlen($heading) + 2);
        $end = preg_match('~^#{1,' . $level . '} ~m', $rest, $match, PREG_OFFSET_CAPTURE) === 1 ? $match[0][1] : strlen($rest);

        return substr($rest, 0, $end);
    }
}

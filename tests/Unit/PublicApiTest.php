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

        $unreleased = self::section((string) file_get_contents(dirname(__DIR__, 2) . '/CHANGELOG.md'), '## [Не выпущено]');
        self::assertStringContainsString('`TransportFactory`', $unreleased);
        self::assertStringContainsString('`SystemClock`', $unreleased);
        self::assertStringContainsString('inner:', $unreleased);
        self::assertStringContainsString('rangeHosts', $unreleased);
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

        $unreleased = self::section((string) file_get_contents(dirname(__DIR__, 2) . '/CHANGELOG.md'), '## [Не выпущено]');
        self::assertStringContainsString('`Webreboot\GdeSlon\Testing`', $unreleased);
        foreach (['FakeHttpTransport', 'FrozenClock', 'InMemoryCacheStore', 'FakeLostOrderClaims'] as $class) {
            self::assertStringContainsString($class, $unreleased);
        }
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

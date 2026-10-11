<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Config;
use Webreboot\GdeSlon\Domain\Claims\ClaimAttachment;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaim;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaimList;
use Webreboot\GdeSlon\Domain\Claims\NewLostOrderClaim;
use Webreboot\GdeSlon\Domain\Claims\OrderTotal;
use Webreboot\GdeSlon\Domain\Sales\Conversion;
use Webreboot\GdeSlon\Domain\Sales\Order;
use Webreboot\GdeSlon\Domain\Sales\OrderCriteria;
use Webreboot\GdeSlon\Domain\Sales\OrderList;
use Webreboot\GdeSlon\Domain\Shared\Money;
use Webreboot\GdeSlon\Infrastructure\Claims\AttachmentFile;
use Webreboot\GdeSlon\Infrastructure\Http\CurlErrorMapper;
use Webreboot\GdeSlon\Infrastructure\Http\HttpRequest;
use Webreboot\GdeSlon\Infrastructure\Http\HttpResponse;
use Webreboot\GdeSlon\Infrastructure\Http\MultipartFormData;
use Webreboot\GdeSlon\Interface\Cli\EnvFile;
use Webreboot\GdeSlon\Interface\Cli\Environment;
use Webreboot\GdeSlon\Interface\Postback\ConversionMapper;
use Webreboot\GdeSlon\Interface\Postback\HeaderSecret;
use Webreboot\GdeSlon\Interface\Postback\PostbackReceiver;
use Webreboot\GdeSlon\Interface\Postback\PostbackRequest;
use Webreboot\GdeSlon\Interface\Postback\QueryStringParser;
use Webreboot\GdeSlon\Interface\Postback\ReceivedPostback;

final class SensitiveParameterCoverageTest extends TestCase
{
    private const SECRET_TYPES = [HttpRequest::class, Config::class, Environment::class, HeaderSecret::class, PostbackRequest::class];

    private const SECRET_NAMES = '~^(?:token|apiToken|apiKey|userId|secret|secrets|password|authorization)$~i';

    private const PERSONAL_DATA_TYPES = [
        HttpResponse::class,
        NewLostOrderClaim::class,
        ClaimAttachment::class,
        OrderCriteria::class,
        LostOrderClaim::class,
        LostOrderClaimList::class,
        Order::class,
        OrderList::class,
        Conversion::class,
        ReceivedPostback::class,
    ];

    private const PERSONAL_DATA_NAMES = '~^(?:body|json|xml|contents|payload)$~i';

    public function testSecretCarryingParametersAreMarked(): void
    {
        [$checked, $unmarked] = self::scan(self::SECRET_TYPES, self::SECRET_NAMES);

        self::assertGreaterThan(30, $checked);
        self::assertSame([], $unmarked, "Параметры с секретом без #[\\SensitiveParameter]:\n" . implode("\n", $unmarked));
    }

    public function testPersonalDataParametersAreMarked(): void
    {
        [$checked, $unmarked] = self::scan(self::PERSONAL_DATA_TYPES, self::PERSONAL_DATA_NAMES);

        self::assertGreaterThan(40, $checked);
        self::assertSame([], $unmarked, "Параметры с персональными данными без #[\\SensitiveParameter]:\n" . implode("\n", $unmarked));
    }

    /**
     * @return iterable<string, array{class-string, string, string}>
     */
    public static function explicitParameters(): iterable
    {
        foreach (['query', 'headers', 'body'] as $name) {
            yield "HttpRequest::__construct \${$name}" => [HttpRequest::class, '__construct', $name];
        }
        yield 'HttpRequest::get $query' => [HttpRequest::class, 'get', 'query'];
        yield 'HttpRequest::get $headers' => [HttpRequest::class, 'get', 'headers'];
        yield 'HttpRequest::post $body' => [HttpRequest::class, 'post', 'body'];
        yield 'HttpRequest::post $headers' => [HttpRequest::class, 'post', 'headers'];
        yield 'HttpRequest::withHeaders $headers' => [HttpRequest::class, 'withHeaders', 'headers'];
        yield 'CurlErrorMapper::toException $error' => [CurlErrorMapper::class, 'toException', 'error'];
        yield 'EnvFile::parse $contents' => [EnvFile::class, 'parse', 'contents'];
        yield 'Environment::__construct $env' => [Environment::class, '__construct', 'env'];
        yield 'Environment::__construct $file' => [Environment::class, '__construct', 'file'];
        yield 'HeaderSecret::__construct $header' => [HeaderSecret::class, '__construct', 'header'];
    }

    /**
     * @return iterable<string, array{class-string, string, string}>
     */
    public static function explicitPersonalDataParameters(): iterable
    {
        foreach (['orderNumber', 'orderDate', 'orderTotal', 'description'] as $name) {
            yield "NewLostOrderClaim::__construct \${$name}" => [NewLostOrderClaim::class, '__construct', $name];
        }
        yield 'NewLostOrderClaim::date $date' => [NewLostOrderClaim::class, 'date', 'date'];
        yield 'NewLostOrderClaim::assertUtf8 $value' => [NewLostOrderClaim::class, 'assertUtf8', 'value'];
        yield 'NewLostOrderClaim::assertLength $value' => [NewLostOrderClaim::class, 'assertLength', 'value'];
        yield 'OrderTotal::of $value' => [OrderTotal::class, 'of', 'value'];
        yield 'ClaimAttachment::fromContents $fileName' => [ClaimAttachment::class, 'fromContents', 'fileName'];
        yield 'AttachmentFile::load $path' => [AttachmentFile::class, 'load', 'path'];
        yield 'MultipartFormData::withField $value' => [MultipartFormData::class, 'withField', 'value'];
        yield 'OrderCriteria::__construct $subId' => [OrderCriteria::class, '__construct', 'subId'];
        yield 'OrderCriteria::normalizeSubId $subId' => [OrderCriteria::class, 'normalizeSubId', 'subId'];
        foreach (['map', 'merchantId', 'state', 'required', 'parameter'] as $method) {
            yield "ConversionMapper::{$method} \$parameters" => [ConversionMapper::class, $method, 'parameters'];
        }
        yield 'QueryStringParser::parse $query' => [QueryStringParser::class, 'parse', 'query'];
        yield 'PostbackRequest::__construct $query' => [PostbackRequest::class, '__construct', 'query'];
        yield 'PostbackRequest::__construct $form' => [PostbackRequest::class, '__construct', 'form'];
        yield 'PostbackRequest::fromServer $post' => [PostbackRequest::class, 'fromServer', 'post'];
        yield 'PostbackReceiver::formParameters $form' => [PostbackReceiver::class, 'formParameters', 'form'];
        yield 'PostbackRequest::fromPsr7 $request' => [PostbackRequest::class, 'fromPsr7', 'request'];
        yield 'OrderList::__construct $orders' => [OrderList::class, '__construct', 'orders'];
        yield 'LostOrderClaimList::__construct $claims' => [LostOrderClaimList::class, '__construct', 'claims'];
        foreach (['reward', 'amount', 'merchantOrderNumber', 'subId', 'keywords'] as $name) {
            yield "Order::__construct \${$name}" => [Order::class, '__construct', $name];
        }
        foreach (['merchantOrderNumber', 'subIds', 'reward', 'orderSum', 'priceInCurrency', 'userAgent', 'clickId'] as $name) {
            yield "Conversion::__construct \${$name}" => [Conversion::class, '__construct', $name];
        }
        yield 'Conversion::normalizeSubIds $subIds' => [Conversion::class, 'normalizeSubIds', 'subIds'];
        foreach (['orderNumber', 'orderDate', 'orderTotal', 'description', 'attachmentUrl'] as $name) {
            yield "LostOrderClaim::__construct \${$name}" => [LostOrderClaim::class, '__construct', $name];
        }
        foreach (['plus', 'compare', 'isGreaterThan', 'isLessThan'] as $method) {
            yield "Money::{$method} \$other" => [Money::class, $method, 'other'];
        }
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('explicitParameters')]
    #[DataProvider('explicitPersonalDataParameters')]
    public function testExplicitParametersAreMarked(string $class, string $method, string $parameter): void
    {
        $found = null;
        foreach ((new \ReflectionMethod($class, $method))->getParameters() as $candidate) {
            if ($candidate->name === $parameter) {
                $found = $candidate;
            }
        }

        self::assertNotNull($found, "Нет параметра \${$parameter}");
        self::assertTrue(self::isMarked($found), "{$class}::{$method}(\${$parameter}) без #[\\SensitiveParameter]");
    }

    public function testLimitationsAreDocumented(): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__, 2) . '/README.md');
        self::assertStringContainsString('zend.exception_ignore_args', $readme);
        self::assertStringContainsString('#[\SensitiveParameter] HttpRequest $request', $readme);

        self::assertStringContainsString('SensitiveParameterValue', (string) file_get_contents(dirname(__DIR__, 2) . '/CHANGELOG.md'));

        self::assertStringContainsString('PostbackRequest', $readme);
        self::assertStringContainsString('#[\SensitiveParameter] NewLostOrderClaim $claim', $readme);
        self::assertStringContainsString('JsonException', (string) file_get_contents(dirname(__DIR__, 2) . '/CHANGELOG.md'));
    }

    /**
     * @return iterable<\ReflectionClass<object>>
     */
    private static function classes(): iterable
    {
        $root = dirname(__DIR__, 2) . '/src';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root) + 1, -4);
            $name = 'Webreboot\\GdeSlon\\' . str_replace(['/', '\\'], '\\', $relative);
            if (class_exists($name) || interface_exists($name) || trait_exists($name) || enum_exists($name)) {
                yield new \ReflectionClass($name);
            }
        }
    }

    /**
     * @param list<class-string> $types
     *
     * @return array{int, list<string>}
     */
    private static function scan(array $types, string $names): array
    {
        $checked = 0;
        $unmarked = [];
        foreach (self::classes() as $class) {
            foreach ($class->getMethods() as $method) {
                if ($method->class !== $class->name) {
                    continue;
                }
                foreach ($method->getParameters() as $parameter) {
                    if (!self::carries($parameter, $types, $names)) {
                        continue;
                    }
                    $checked++;
                    if (!self::isMarked($parameter)) {
                        $unmarked[] = sprintf('%s::%s($%s)', $class->name, $method->name, $parameter->name);
                    }
                }
            }
        }

        return [$checked, $unmarked];
    }

    /**
     * @param list<class-string> $types
     */
    private static function carries(\ReflectionParameter $parameter, array $types, string $names): bool
    {
        $type = $parameter->getType();
        $flag = $type instanceof \ReflectionNamedType && $type->getName() === 'bool';
        if (!$flag && preg_match($names, $parameter->name) === 1) {
            return true;
        }

        $singles = $type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType ? $type->getTypes() : [$type];
        foreach ($singles as $single) {
            if ($single instanceof \ReflectionNamedType && in_array($single->getName(), $types, true)) {
                return true;
            }
        }

        return false;
    }

    private static function isMarked(\ReflectionParameter $parameter): bool
    {
        foreach ($parameter->getAttributes() as $attribute) {
            if ($attribute->getName() === 'SensitiveParameter') {
                return true;
            }
        }

        return false;
    }
}

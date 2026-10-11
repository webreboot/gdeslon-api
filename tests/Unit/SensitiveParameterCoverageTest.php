<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Config;
use Webreboot\GdeSlon\Infrastructure\Http\CurlErrorMapper;
use Webreboot\GdeSlon\Infrastructure\Http\HttpRequest;
use Webreboot\GdeSlon\Interface\Cli\EnvFile;
use Webreboot\GdeSlon\Interface\Cli\Environment;
use Webreboot\GdeSlon\Interface\Postback\HeaderSecret;
use Webreboot\GdeSlon\Interface\Postback\PostbackRequest;

final class SensitiveParameterCoverageTest extends TestCase
{
    private const SECRET_TYPES = [HttpRequest::class, Config::class, Environment::class, HeaderSecret::class, PostbackRequest::class];

    private const SECRET_NAMES = '~^(?:token|apiToken|apiKey|userId|secret|secrets|password|authorization)$~i';

    public function testSecretCarryingParametersAreMarked(): void
    {
        $checked = 0;
        $unmarked = [];
        foreach (self::classes() as $class) {
            foreach ($class->getMethods() as $method) {
                if ($method->class !== $class->name) {
                    continue;
                }
                foreach ($method->getParameters() as $parameter) {
                    if (!self::carriesSecret($parameter)) {
                        continue;
                    }
                    $checked++;
                    if (!self::isMarked($parameter)) {
                        $unmarked[] = sprintf('%s::%s($%s)', $class->name, $method->name, $parameter->name);
                    }
                }
            }
        }

        self::assertGreaterThan(30, $checked);
        self::assertSame([], $unmarked, "Параметры с секретом без #[\\SensitiveParameter]:\n" . implode("\n", $unmarked));
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
     * @param class-string $class
     */
    #[DataProvider('explicitParameters')]
    public function testExplicitSecretParametersAreMarked(string $class, string $method, string $parameter): void
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

    private static function carriesSecret(\ReflectionParameter $parameter): bool
    {
        if (preg_match(self::SECRET_NAMES, $parameter->name) === 1) {
            return true;
        }

        $type = $parameter->getType();
        $types = $type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType ? $type->getTypes() : [$type];
        foreach ($types as $single) {
            if ($single instanceof \ReflectionNamedType && in_array($single->getName(), self::SECRET_TYPES, true)) {
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

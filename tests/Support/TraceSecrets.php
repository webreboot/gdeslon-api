<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Support;

use PHPUnit\Framework\Assert;

trait TraceSecrets
{
    private static function requireSensitiveParameter(): void
    {
        if (PHP_VERSION_ID < 80200) {
            Assert::markTestSkipped('#[\SensitiveParameter] действует с PHP 8.2');
        }
    }

    /**
     * @template T of \Throwable
     * @param callable(): mixed $action
     * @param class-string<T>   $expected
     *
     * @return T
     */
    private static function catchWithTraceArgs(callable $action, string $expected): \Throwable
    {
        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');
        try {
            $action();
        } catch (\Throwable $e) {
            if (!$e instanceof $expected) {
                throw $e;
            }

            return $e;
        } finally {
            if ($ignoreArgs !== false) {
                ini_set('zend.exception_ignore_args', $ignoreArgs);
            }
        }

        Assert::fail('Ожидалось исключение ' . $expected);
    }

    private static function assertTraceHidesSecrets(\Throwable $e, string ...$secrets): void
    {
        $exported = '';
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            $dump = var_export(self::packageFrameArgs($current), true) . print_r(self::packageFrameArgs($current), true);
            foreach (self::packageFrameArgs($current) as $args) {
                $dump .= self::serializedOrEmpty($args);
            }
            foreach ($secrets as $secret) {
                Assert::assertStringNotContainsString($secret, $dump, $current::class);
            }
            $exported .= var_export(self::packageFrameArgs($current, false), true);
        }
        $string = self::fullString($e);
        foreach ($secrets as $secret) {
            Assert::assertStringNotContainsString($secret, $string);
        }

        Assert::assertStringContainsString('\SensitiveParameterValue::__set_state', $exported, 'В кадрах пакета нет ни одного скрытого аргумента: проверка прошла бы впустую');
    }

    private static function assertSerializedTraceHidesSecrets(\Throwable $e, string ...$secrets): void
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            $frames = self::packageFrameArgs($current);
            Assert::assertNotSame([], $frames, 'В трассе нет аргументов кадров пакета: ' . $current::class);
            foreach ($frames as $args) {
                $serialized = self::serializedOrEmpty($args);
                foreach ($secrets as $secret) {
                    Assert::assertStringNotContainsString($secret, $serialized, $current::class);
                }
            }
        }
    }

    private static function assertNoFrame(\Throwable $e, string $function): void
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            foreach ($current->getTrace() as $frame) {
                Assert::assertFalse(
                    !isset($frame['class']) && $frame['function'] === $function,
                    sprintf('Кадр %s() в трассе %s', $function, $current::class),
                );
            }
        }
    }

    private static function assertTraceArgsSmallerThan(\Throwable $e, int $bytes): void
    {
        $size = 0;
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            $size += strlen(var_export(self::packageFrameArgs($current), true));
        }

        Assert::assertLessThan($bytes, $size, 'Аргументы трассы слишком велики');
    }

    /**
     * @return list<array<mixed>>
     */
    private static function packageFrameArgs(\Throwable $e, bool $withSupport = true): array
    {
        $tests = $withSupport ? '~^Webreboot\\\\GdeSlon\\\\Tests\\\\(?:Unit|Integration)\\\\~' : '~^Webreboot\\\\GdeSlon\\\\(?:Tests|Testing)\\\\~';
        $args = [];
        foreach ($e->getTrace() as $frame) {
            $class = $frame['class'] ?? '';
            if (str_starts_with($class, 'PHPUnit\\') || preg_match($tests, $class) === 1) {
                continue;
            }
            if (($frame['args'] ?? []) !== []) {
                $args[] = $frame['args'];
            }
        }

        return $args;
    }

    private static function fullString(\Throwable $e): string
    {
        $maxLength = ini_set('zend.exception_string_param_max_len', '1000000');
        try {
            return (string) $e;
        } finally {
            if ($maxLength !== false) {
                ini_set('zend.exception_string_param_max_len', $maxLength);
            }
        }
    }

    private static function serializedOrEmpty(mixed $value): string
    {
        try {
            return serialize($value);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }
}

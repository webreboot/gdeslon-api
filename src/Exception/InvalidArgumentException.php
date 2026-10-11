<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Exception;

final class InvalidArgumentException extends \InvalidArgumentException implements GdeSlonException
{
    /**
     * @internal
     */
    public static function withoutTraceArguments(string $message): self
    {
        if (\function_exists('ini_set')) {
            $ignoreArgs = ini_set('zend.exception_ignore_args', '1');
            try {
                $exception = new self($message);
            } finally {
                if ($ignoreArgs !== false) {
                    ini_set('zend.exception_ignore_args', $ignoreArgs);
                }
            }
        } else {
            // disable_functions=ini_set: настройку не поменять — хотя бы исключение пакета, а не \Error
            $exception = new self($message);
        }

        $caller = $exception->getTrace()[0] ?? [];
        $exception->file = $caller['file'] ?? $exception->file;
        $exception->line = $caller['line'] ?? $exception->line;

        return $exception;
    }
}

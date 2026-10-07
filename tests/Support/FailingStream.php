<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Support;

final class FailingStream
{
    public static bool $failing = false;

    /** @var resource|null */
    public $context;

    /**
     * @return resource
     */
    public static function open()
    {
        if (!in_array('failing', stream_get_wrappers(), true)) {
            stream_wrapper_register('failing', self::class);
        }
        self::$failing = false;
        $stream = fopen('failing://stream', 'wb');
        if ($stream === false) {
            throw new \RuntimeException('failing://');
        }

        return $stream;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        return self::$failing ? 0 : strlen($data);
    }

    public function stream_eof(): bool
    {
        return true;
    }
}

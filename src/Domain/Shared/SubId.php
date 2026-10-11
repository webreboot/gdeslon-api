<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Shared;

use Webreboot\GdeSlon\Exception\InvalidArgumentException;

final class SubId implements \Stringable
{
    public const MAX_LENGTH = 64;

    public const MAX_POSITION = 5;

    private readonly string $value;

    /**
     * @throws InvalidArgumentException
     */
    public function __construct(#[\SensitiveParameter] string $value)
    {
        if (!self::isValid($value)) {
            throw new InvalidArgumentException(sprintf('sub_id: ожидалось от 1 до %d символов A–Z, a–z, 0–9, «_» и «-»', self::MAX_LENGTH));
        }

        $this->value = $value;
    }

    public static function isValid(#[\SensitiveParameter] string $value): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{1,' . self::MAX_LENGTH . '}\z/', $value) === 1;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

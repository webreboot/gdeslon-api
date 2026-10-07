<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Postback;

use Webreboot\GdeSlon\Domain\Sales\Conversion;

final class ReceivedPostback
{
    /**
     * @param list<string>                         $warnings
     * @param array<string, string|NonScalarValue> $parameters
     */
    public function __construct(
        private readonly Conversion $conversion,
        private readonly PostbackFormat $format,
        private readonly array $warnings,
        private readonly array $parameters,
    ) {
    }

    public function conversion(): Conversion
    {
        return $this->conversion;
    }

    public function format(): PostbackFormat
    {
        return $this->format;
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    public function parameter(string $name): ?string
    {
        $value = $this->parameters[$name] ?? null;

        return is_string($value) ? $value : null;
    }
}

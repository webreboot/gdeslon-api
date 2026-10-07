<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Domain\Promo;

use Webreboot\GdeSlon\Exception\HttpException;

final class CouponCriteriaRejectedException extends HttpException
{
    /**
     * @param array<string, list<string>> $errors
     */
    public function __construct(
        string $message,
        int $statusCode,
        string $method,
        string $url,
        string $responseSnippet,
        private readonly array $errors,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $method, $url, $responseSnippet, $previous);
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}

<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Mcp;

use Webreboot\GdeSlon\Exception\GdeSlonException;

/**
 * @internal
 */
final class JsonRpcError extends \RuntimeException implements GdeSlonException
{
    public const PARSE_ERROR = -32700;
    public const INVALID_REQUEST = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS = -32602;
    public const INTERNAL_ERROR = -32603;
    public const UNSUPPORTED_PROTOCOL_VERSION = -32022;

    /**
     * @param array<string, mixed>|null $data
     */
    private function __construct(int $code, string $message, private readonly ?array $data = null)
    {
        parent::__construct($message, $code);
    }

    public static function parse(): self
    {
        return new self(self::PARSE_ERROR, 'Parse error');
    }

    public static function invalidRequest(string $reason = 'Invalid Request'): self
    {
        return new self(self::INVALID_REQUEST, $reason);
    }

    public static function methodNotFound(string $method): self
    {
        return new self(self::METHOD_NOT_FOUND, sprintf('Method not found: %s', $method));
    }

    public static function invalidParams(string $reason): self
    {
        return new self(self::INVALID_PARAMS, $reason);
    }

    public static function internal(): self
    {
        return new self(self::INTERNAL_ERROR, 'Internal error');
    }

    public static function unsupportedVersion(string $requested): self
    {
        return new self(self::UNSUPPORTED_PROTOCOL_VERSION, 'Unsupported protocol version', [
            'supported' => ProtocolVersion::MODERN,
            'requested' => $requested,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $error = ['code' => $this->getCode(), 'message' => $this->getMessage()];

        return $this->data === null ? $error : $error + ['data' => $this->data];
    }
}

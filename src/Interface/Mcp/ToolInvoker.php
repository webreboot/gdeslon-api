<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Mcp;

use Webreboot\GdeSlon\Exception\GdeSlonException;
use Webreboot\GdeSlon\Interface\Cli\ErrorReporter;
use Webreboot\GdeSlon\Interface\Cli\ExitCode;
use Webreboot\GdeSlon\Interface\Mcp\Tool\Tool;

/**
 * Вызов инструмента → CallToolResult. Ошибки выполнения и неверные аргументы — результат с isError и стабильным
 * тегом в начале текста ([access], [invalid_arguments], …), а не ошибка JSON-RPC: так их видит модель.
 *
 * @internal
 */
final class ToolInvoker
{
    /** Больше — не помещается в контекст агента; клиенты сохраняют такие ответы в файл или обрезают. */
    public const MAX_RESULT_BYTES = 400000;

    public function __construct(private readonly ToolContext $context)
    {
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \JsonException результат не кодируется (ошибка сервера, -32603)
     */
    public function call(Tool $tool, \stdClass $arguments, bool $structured): array
    {
        try {
            $this->context->environment->assertCredentials($tool->credentials());
            $result = $tool->call(new Arguments($arguments), $this->context);
        } catch (\Throwable $error) {
            return $this->error($error);
        }

        if (!($this->context->revealLinks && $tool->exposesToken())) {
            $result = $this->mask($result);
        }
        $text = Json::encode(Json::object($result));
        if (strlen($text) > self::MAX_RESULT_BYTES) {
            return self::failure('too_large', sprintf(
                'Ответ %d КБ больше %d КБ: уменьшите limit или сузьте фильтры.',
                intdiv(strlen($text), 1024),
                intdiv(self::MAX_RESULT_BYTES, 1024),
            ));
        }

        $response = ['content' => [Json::object(['type' => 'text', 'text' => $text])]];

        return $structured ? $response + ['structuredContent' => Json::object($result)] : $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function error(\Throwable $error): array
    {
        if ($error instanceof NotFoundException) {
            return $this->failureMasked('not_found', $error->getMessage());
        }
        [$code, $text] = ErrorReporter::report($error);
        $tag = match ($code) {
            ExitCode::USAGE => 'invalid_arguments',
            ExitCode::ACCESS => 'access',
            ExitCode::CLAIM_UNCONFIRMED => 'claim_unconfirmed',
            ExitCode::CLAIM_DUPLICATE => 'claim_duplicate',
            default => $error instanceof GdeSlonException ? 'failed' : 'internal',
        };
        if ($tag === 'internal') {
            $this->context->log(sprintf('внутренняя ошибка %s: %s', $error::class, $error->getMessage()));
        }

        return $this->failureMasked($tag, rtrim($text));
    }

    /**
     * @return array<string, mixed>
     */
    private function failureMasked(string $tag, string $text): array
    {
        return self::failure($tag, $this->context->console->mask($text));
    }

    /**
     * @return array<string, mixed>
     */
    private static function failure(string $tag, string $text): array
    {
        return ['content' => [Json::object(['type' => 'text', 'text' => sprintf('[%s] %s', $tag, $text)])], 'isError' => true];
    }

    /**
     * Маска значений до кодирования: по готовой JSON-строке замена могла бы задеть её структуру.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function mask(array $data): array
    {
        array_walk_recursive($data, function (mixed &$value): void {
            if (is_string($value)) {
                $value = $this->context->console->mask($value);
            }
        });

        return $data;
    }
}

<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Postback;

use Webreboot\GdeSlon\Exception\InvalidArgumentException;

/**
 * Секрет postback в HTTP-заголовке (в кабинете «Где Слон?»: заголовок «Название → Значение»). Подписи у postback нет,
 * это единственная защита от поддельных уведомлений — используйте длинное случайное значение и HTTPS.
 *
 * Рекомендуемый заголовок — `X-Gdeslon-Secret`: `Authorization` Apache/CGI без настройки не передают в PHP, а nginx
 * отбрасывает заголовки с «_». Сравнение — за постоянное время; значение не попадает в сообщения и дампы, а имя
 * заголовка не называется в ответе 401 (его видит кто угодно).
 */
final class HeaderSecret
{
    public const MIN_LENGTH = 16;

    private readonly string $header;

    private readonly string $value;

    public function __construct(string $header, #[\SensitiveParameter] string $value)
    {
        $header = trim($header);
        if (preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $header) !== 1) {
            // имя не печатаем: при перепутанных аргументах в нём окажется секрет
            throw new InvalidArgumentException('Неверное имя заголовка секрета: ожидались латинские буквы, цифры и «-» (например X-Gdeslon-Secret)');
        }
        $value = trim($value);
        if (strcasecmp($value, $header) === 0) {
            throw new InvalidArgumentException('Значение секрета совпадает с именем заголовка — перепутаны аргументы HeaderSecret(header, value)?');
        }
        if (strlen($value) < self::MIN_LENGTH) {
            throw new InvalidArgumentException(sprintf('Секрет postback должен быть не короче %d символов', self::MIN_LENGTH));
        }

        $this->header = $header;
        $this->value = $value;
    }

    public function header(): string
    {
        return $this->header;
    }

    /**
     * @throws PostbackAuthenticationException
     */
    public function verify(#[\SensitiveParameter] PostbackRequest $request): void
    {
        $values = $request->headerValues($this->header);
        if ($values === []) {
            throw new PostbackAuthenticationException('Postback: нет секрета');
        }
        if (count($values) > 1) {
            throw new PostbackAuthenticationException(sprintf('Postback: секрет передан %d раз', count($values)));
        }
        if (!hash_equals($this->value, trim($values[0]))) {
            throw new PostbackAuthenticationException('Postback: неверный секрет');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['header' => $this->header, 'value' => '***'];
    }
}

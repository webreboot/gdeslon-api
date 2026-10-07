<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Postback;

use Webreboot\GdeSlon\Domain\Sales\Conversion;

/**
 * Принятый postback: конверсия, формат запроса, предупреждения о битых необязательных полях и сырые параметры.
 */
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

    /**
     * Почему отдельные поля пропущены (null в конверсии): битая сумма, время неизвестного формата и т. п. Значений
     * sub_id и других пользовательских данных в причинах нет — их можно писать в лог.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * Сырое значение любого полученного параметра по его имени в запросе (например недокументированные `action_ip`,
     * `token`); null — параметра нет или он не строка. Недоверенный ввод.
     */
    public function parameter(string $name): ?string
    {
        $value = $this->parameters[$name] ?? null;

        return is_string($value) ? $value : null;
    }
}

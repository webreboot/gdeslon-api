<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Postback;

use Webreboot\GdeSlon\Exception\InvalidArgumentException;

/**
 * Карта полей postback: как в кабинете «Где Слон?» названы параметры («Получаемое имя») для макросов. По умолчанию имя
 * параметра = имя макроса (`profit` → `profit`).
 *
 * ```php
 * new PostbackFields(['order_id' => 'someOrderId', '*profit*' => 'myProfit']);
 * ```
 */
final class PostbackFields
{
    /** Без них postback не принять — их имя нельзя занять другим макросом. */
    private const REQUIRED = [PostbackMacro::MerchantId, PostbackMacro::State];

    /** @var array<string, string|null> макрос → имя параметра; null — макрос не передаётся */
    private readonly array $names;

    /**
     * Явное имя вытесняет совпадающее имя по умолчанию: `['gs_order_id' => 'order_id']` — ID заказа «Где Слон?» читается
     * из `order_id`, а макрос `*order_id*` считается непереданным. Два явных имени не могут совпадать.
     *
     * При multipart форма берётся из $_POST, где PHP заменяет точки и пробелы в именах на «_» — называйте параметры
     * латиницей, цифрами и «_».
     *
     * @param array<string, string> $overrides макрос (`profit` или `*profit*`) → имя параметра у вебмастера
     */
    public function __construct(array $overrides = [])
    {
        $explicit = [];
        foreach ($overrides as $macro => $name) {
            $key = trim(trim($macro), '*');
            if (PostbackMacro::tryFrom($key) === null) {
                throw new InvalidArgumentException(sprintf(
                    'Неизвестный макрос postback «%s»; допустимые: %s',
                    $macro,
                    implode(', ', array_map(static fn (PostbackMacro $m): string => $m->value, PostbackMacro::cases())),
                ));
            }
            $name = trim($name);
            if ($name === '') {
                throw new InvalidArgumentException(sprintf('Пустое имя параметра для макроса %s', $key));
            }
            $other = array_search($name, $explicit, true);
            if ($other !== false) {
                throw new InvalidArgumentException(sprintf('Параметр «%s» назначен двум макросам: %s и %s', $name, $other, $key));
            }
            $explicit[$key] = $name;
        }

        $names = [];
        foreach (PostbackMacro::cases() as $macro) {
            if (isset($explicit[$macro->value])) {
                $names[$macro->value] = $explicit[$macro->value];
            } elseif (in_array($macro->value, $explicit, true)) {
                if (in_array($macro, self::REQUIRED, true)) {
                    throw new InvalidArgumentException(sprintf(
                        'Имя «%s» обязательного макроса %s занято макросом %s — назовите %s иначе',
                        $macro->value,
                        $macro->value,
                        array_search($macro->value, $explicit, true),
                        $macro->value,
                    ));
                }
                $names[$macro->value] = null;
            } else {
                $names[$macro->value] = $macro->value;
            }
        }

        $this->names = $names;
    }

    /**
     * Имя параметра макроса; null — макрос не передаётся (его имя занято другим макросом).
     */
    public function nameOf(PostbackMacro $macro): ?string
    {
        return $this->names[$macro->value];
    }
}

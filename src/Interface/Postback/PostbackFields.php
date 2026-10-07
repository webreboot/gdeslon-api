<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Postback;

use Webreboot\GdeSlon\Exception\InvalidArgumentException;

final class PostbackFields
{
    private const REQUIRED = [PostbackMacro::MerchantId, PostbackMacro::State];

    /** @var array<string, string|null> */
    private readonly array $names;

    /** @param array<string, string> $overrides */
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

    public function nameOf(PostbackMacro $macro): ?string
    {
        return $this->names[$macro->value];
    }
}

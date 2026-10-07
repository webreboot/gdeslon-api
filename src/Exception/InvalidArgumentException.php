<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Exception;

/**
 * Неверное значение: нарушен инвариант доменного объекта или некорректная настройка.
 */
final class InvalidArgumentException extends \InvalidArgumentException implements GdeSlonException
{
}

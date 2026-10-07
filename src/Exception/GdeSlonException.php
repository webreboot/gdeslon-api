<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Exception;

/**
 * Общий маркер всех исключений пакета: `catch (GdeSlonException $e)` ловит любую ошибку библиотеки.
 */
interface GdeSlonException extends \Throwable
{
}

<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Exception;

/**
 * Ключ или токен API не указан, неверен или не даёт доступа: 401/403, а также ответ 200, по содержимому которого видно,
 * что токен не принят (shops.xml с токеном без единой партнёрской ссылки — statusCode() тогда 200).
 */
final class AuthenticationException extends HttpException
{
}

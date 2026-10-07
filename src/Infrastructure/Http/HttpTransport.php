<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Http;

use Webreboot\GdeSlon\Exception\TransportException;

/**
 * Отправка HTTP-запросов. Точка расширения: можно подставить свою реализацию (прокси, логирование, PSR-18).
 *
 * Ответ с любым статусом, включая 4xx и 5xx, — обычный HttpResponse; исключение — только если ответ не получен.
 *
 * Реализация должна:
 * - отправлять метод и тело запроса как есть: HttpRequest::body(), если не null, — байт-в-байт (POST API по продажам
 *   с JSON-фильтрами; без тела API отвечает «нет заказов», и ошибка останется незамеченной);
 * - соблюдать HttpRequest::timeout() (ждать не дольше меньшего из своего таймаута и запросного);
 * - не переиспользовать соединение, если в запросе `Connection: close` (на этом держится RangeTransport).
 */
interface HttpTransport
{
    /**
     * @throws TransportException нет сети, таймаут, ошибка TLS, обрыв соединения
     */
    public function send(HttpRequest $request): HttpResponse;
}

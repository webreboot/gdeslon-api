<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Postback;

/**
 * Макросы postback «Где Слон?» (docs/gdeslon-api/postback.md); значение — имя макроса без звёздочек.
 */
enum PostbackMacro: string
{
    /** ID заказа в «Где Слон?». */
    case GsOrderId = 'gs_order_id';
    case MerchantId = 'merchant_id';
    case SubId = 'sub_id';
    case SubId2 = 'sub_id2';
    case SubId3 = 'sub_id3';
    case SubId4 = 'sub_id4';
    case SubId5 = 'sub_id5';
    /** Заработок вебмастера. */
    case Profit = 'profit';
    /** Номер заказа у магазина. */
    case OrderId = 'order_id';
    case OrderSum = 'order_sum';
    case ClickTime = 'click_time';
    case ActionTime = 'action_time';
    case UserAgent = 'user_agent';
    case State = 'state';
    case PriceInCurrency = 'price_in_currency';
    case OfferName = 'offer_name';
    case Currency = 'currency';
    /** Есть в настройке постбэка и FAQ 60, в описании макросов FAQ 24 — нет. */
    case ClickId = 'click_id';
}

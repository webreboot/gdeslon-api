<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Postback;

enum PostbackMacro: string
{
    case GsOrderId = 'gs_order_id';
    case MerchantId = 'merchant_id';
    case SubId = 'sub_id';
    case SubId2 = 'sub_id2';
    case SubId3 = 'sub_id3';
    case SubId4 = 'sub_id4';
    case SubId5 = 'sub_id5';
    case Profit = 'profit';
    case OrderId = 'order_id';
    case OrderSum = 'order_sum';
    case ClickTime = 'click_time';
    case ActionTime = 'action_time';
    case UserAgent = 'user_agent';
    case State = 'state';
    case PriceInCurrency = 'price_in_currency';
    case OfferName = 'offer_name';
    case Currency = 'currency';
    case ClickId = 'click_id';
}

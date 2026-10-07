<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Infrastructure\Api\Claims;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaim;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaimList;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaimState;
use Webreboot\GdeSlon\Domain\Claims\LostOrderCriteria;
use Webreboot\GdeSlon\Domain\Claims\LostOrderStatus;
use Webreboot\GdeSlon\Exception\UnexpectedResponseException;
use Webreboot\GdeSlon\Infrastructure\Api\Claims\LostOrderClaimMapper;
use Webreboot\GdeSlon\Tests\Support\Fixtures;

/**
 * claims-synthetic.json, claim-synthetic.json — СИНТЕТИКА по FAQ 74: у аккаунта автора нет заявок, форма непустого ответа
 * живьём не проверена (docs/gdeslon-api/lost-orders.md). По первому реальному ответу — заменить обезличенным.
 */
final class LostOrderClaimMapperTest extends TestCase
{
    public function testFullClaim(): void
    {
        $claim = self::claim(5796);

        self::assertSame('2342lost', $claim->orderNumber());
        self::assertSame('2026-09-24T00:00:00+03:00', $claim->orderDate()->format(DATE_ATOM));
        self::assertSame('12020.22', $claim->orderTotal()->amount());
        self::assertSame(54376, $claim->merchantId()->value());
        self::assertSame('Магазин Пример', $claim->merchantName());
        self::assertSame('Заказ после перехода с моего сайта', $claim->description());
        self::assertSame('http://gdeslon.ru/uploads/ticket/path/to/file.jpg', $claim->attachmentUrl());
        self::assertSame(LostOrderStatus::Waiting, $claim->orderStatus(), 'in_waiting');
        self::assertSame(LostOrderClaimState::InWork, $claim->claimState());
        self::assertSame('2026-09-27', $claim->orderUpdatedAt()?->format('Y-m-d'));
    }

    public function testVariantsFromDocumentation(): void
    {
        $second = self::claim(5797);
        self::assertSame('12312.00', $second->orderTotal()->amount());
        self::assertSame(LostOrderStatus::Waiting, $second->orderStatus(), 'waiting');
        self::assertSame('2026-09-21T10:00:00+03:00', $second->orderUpdatedAt()?->format(DATE_ATOM), 'sale_updated_at');
        self::assertNull($second->description());
        self::assertNull($second->attachmentUrl());

        $third = self::claim(5798);
        self::assertSame('554.34', $third->orderTotal()->amount());
        self::assertSame(2573, $third->merchantId()->value());
        self::assertSame(LostOrderClaimState::Closed, $third->claimState());
        self::assertNull($third->orderUpdatedAt());

        $fourth = self::claim(5799);
        self::assertSame('100.00', $fourth->orderTotal()->amount());
        self::assertSame('2026-07-15', $fourth->orderDate()->format('Y-m-d'));
        self::assertSame(LostOrderClaimState::Closed, $fourth->claimState(), 'ticket_status вместо ticket_state');
        self::assertNull($fourth->merchantName());
    }

    public function testListFormsAndClientFilter(): void
    {
        $list = self::map(Fixtures::json('lost-orders/claims-synthetic.json'));
        self::assertCount(4, $list);
        self::assertSame([], $list->skipped());

        self::assertTrue(self::map(Fixtures::json('lost-orders/list-empty.json'))->isEmpty());
        self::assertTrue(self::map(Fixtures::json('lost-orders/list-paginated-empty.json'))->isEmpty());
        self::assertCount(4, self::map(['count' => 4, 'next' => null, 'previous' => null, 'results' => Fixtures::json('lost-orders/claims-synthetic.json')]));

        $filtered = (new LostOrderClaimMapper())->toList(Fixtures::json('lost-orders/claims-synthetic.json'), new LostOrderCriteria(merchant: 2573, orderStatus: LostOrderStatus::Waiting));
        self::assertSame([5797], array_map(static fn (LostOrderClaim $c): int => $c->id()->value(), $filtered->all()), 'сервер мог проигнорировать фильтр');
    }

    #[DataProvider('brokenRecords')]
    public function testBrokenRecordIsSkipped(string $field, mixed $value): void
    {
        $records = Fixtures::json('lost-orders/claims-synthetic.json');
        self::assertIsArray($records);
        self::assertIsArray($records[1]);
        $records[1][$field] = $value;

        $list = self::map($records);

        self::assertCount(3, $list);
        self::assertCount(1, $list->skipped());
        self::assertStringStartsWith('заявка #2', $list->skipped()[0]);
        self::assertStringContainsString($field, $list->skipped()[0]);
        self::assertStringNotContainsString('GS123L', $list->skipped()[0], 'номер заказа покупателя в причину не пишется');
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function brokenRecords(): iterable
    {
        yield 'сумма три знака' => ['order_total', 0.333];
        yield 'сумма отрицательная' => ['order_total', -5];
        yield 'сумма словом' => ['order_total', 'abc'];
        yield 'нет суммы' => ['order_total', null];
        yield 'id ноль' => ['id', 0];
        yield 'id null' => ['id', null];
        yield 'id словом' => ['id', 'abc'];
        yield 'магазин словом' => ['merchant_id', 'abc'];
        yield 'магазин ноль' => ['merchant_id', 0];
        yield 'неизвестный статус' => ['order_status', 'processing'];
        yield 'нет статуса' => ['order_status', null];
        yield 'неизвестное состояние' => ['ticket_state', 'open'];
        yield '31 февраля' => ['order_date', '2026-02-31'];
        yield 'дата словом' => ['order_date', 'вчера'];
        yield 'нет номера' => ['order_id', ''];
        yield 'описание массивом' => ['description', ['x']];
    }

    public function testNotDocuments(): void
    {
        foreach ([Fixtures::json('lost-orders/list-paginated-next-synthetic.json'), ['results' => 'x'], 'x', 5, null] as $payload) {
            try {
                self::map($payload);
                self::fail('Ожидалось исключение: ' . var_export($payload, true));
            } catch (UnexpectedResponseException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testAllBrokenAndDuplicates(): void
    {
        try {
            self::map([['id' => 1], ['id' => 2]]);
            self::fail('Ожидалось исключение');
        } catch (UnexpectedResponseException $e) {
            self::assertStringContainsString('ни одна заявка не разобрана (2)', $e->getMessage());
        }

        $records = Fixtures::json('lost-orders/claims-synthetic.json');
        self::assertIsArray($records);
        $list = self::map([$records[0], $records[0], 'скаляр', [1, 2]]);
        self::assertCount(1, $list);
        self::assertCount(3, $list->skipped());
        self::assertStringContainsString('повтор', $list->skipped()[0]);
    }

    public function testStrictClaim(): void
    {
        self::assertSame(5796, (new LostOrderClaimMapper())->toClaim(Fixtures::json('lost-orders/claim-synthetic.json'))->id()->value());

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('order_status');
        (new LostOrderClaimMapper())->toClaim(['id' => 1, 'order_id' => 'x', 'order_date' => '2026-09-24', 'order_total' => 1, 'merchant_id' => 1, 'order_status' => 'x', 'ticket_state' => 'in_work']);
    }

    public function testErrors(): void
    {
        $mapper = new LostOrderClaimMapper();

        self::assertSame(['start_date' => ['Введите правильную дату.']], $mapper->errors(Fixtures::read('lost-orders/error-400-start-date.json')));
        self::assertSame(['detail' => ['Не найдено.']], $mapper->errors(Fixtures::read('lost-orders/error-404.json')));
        self::assertSame(
            ['order_date' => ['Дата заказа не может быть старше 3 месяцев.'], 'attachment' => ['Загрузите правильное изображение.']],
            $mapper->errors(Fixtures::read('lost-orders/error-400-create-synthetic.json')),
        );
        foreach (['<html>500</html>', '[]', '{"detail":"x"}', '{"errors":[]}', ''] as $body) {
            self::assertNull($mapper->errors($body), $body);
        }
    }

    private static function map(mixed $payload): LostOrderClaimList
    {
        return (new LostOrderClaimMapper())->toList($payload, new LostOrderCriteria());
    }

    private static function claim(int $id): LostOrderClaim
    {
        return self::map(Fixtures::json('lost-orders/claims-synthetic.json'))->find($id) ?? throw new \LogicException('Нет заявки ' . $id);
    }

    public function testSkipReasonsHaveNoCustomerValues(): void
    {
        $records = Fixtures::json('lost-orders/claims-synthetic.json');
        self::assertIsArray($records);
        self::assertIsArray($records[1]);
        self::assertIsArray($records[2]);
        $records[1]['order_total'] = 0.333;
        $records[2]['order_date'] = '2026-02-31';

        $skipped = implode("\n", self::map($records)->skipped());

        self::assertStringContainsString('order_total', $skipped);
        self::assertStringContainsString('order_date', $skipped);
        self::assertStringNotContainsString('0.333', $skipped);
        self::assertStringNotContainsString('2026-02-31', $skipped);
    }

    public function testOrderDateIsMoscowDay(): void
    {
        $records = Fixtures::json('lost-orders/claims-synthetic.json');
        self::assertIsArray($records);
        self::assertIsArray($records[0]);
        $records[0]['order_date'] = '2026-07-15T23:30:00Z';

        self::assertSame('2026-07-16T00:00:00+03:00', self::map([$records[0]])->find(5796)?->orderDate()->format(DATE_ATOM));
        self::assertSame('2026-07-15T00:00:00+03:00', self::claim(5799)->orderDate()->format(DATE_ATOM), '+03:00 10:00 — день, а не момент');
    }

    public function testStrictClaimDoesNotLeakTotalThroughChain(): void
    {
        $record = Fixtures::json('lost-orders/claim-synthetic.json');
        self::assertIsArray($record);
        $record['order_total'] = 0.333;

        try {
            (new LostOrderClaimMapper())->toClaim($record);
            self::fail('Ожидалось исключение');
        } catch (UnexpectedResponseException $e) {
            self::assertStringContainsString('order_total', $e->getMessage());
            self::assertStringNotContainsString('0.333', (string) $e, 'ни в сообщении, ни в цепочке previous');
        }
    }
}

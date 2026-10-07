<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Integration\Interface\Cli;

use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Claims\ClaimAttachment;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaim;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaimId;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaimList;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaims;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaimUnconfirmedException;
use Webreboot\GdeSlon\Domain\Claims\LostOrderCriteria;
use Webreboot\GdeSlon\Domain\Claims\NewLostOrderClaim;
use Webreboot\GdeSlon\Exception\TimeoutException;
use Webreboot\GdeSlon\Exception\UnexpectedResponseException;
use Webreboot\GdeSlon\GdeSlon;
use Webreboot\GdeSlon\Interface\Cli\Application;
use Webreboot\GdeSlon\Interface\Cli\Console;
use Webreboot\GdeSlon\Tests\Support\CliTester;
use Webreboot\GdeSlon\Tests\Support\FailingStream;
use Webreboot\GdeSlon\Tests\Support\FakeHttpTransport;
use Webreboot\GdeSlon\Tests\Support\FakeLostOrderClaims;
use Webreboot\GdeSlon\Tests\Support\Fixtures;
use Webreboot\GdeSlon\Tests\Support\FrozenClock;

/**
 * Заказы и заявки на потерянные заказы. POST заявок — только FakeHttpTransport/FakeLostOrderClaims: живьём
 * POST /api/v1/lost-orders/ не вызывается никогда.
 */
final class SalesCommandsTest extends TestCase
{
    private const TOKEN = ['GDESLON_API_TOKEN' => 'secret-token-0123'];
    private const SALES = ['GDESLON_USER_ID' => '1234', 'GDESLON_API_KEY' => 'test-api-key'];

    private string $attachment;

    protected function setUp(): void
    {
        $this->attachment = sys_get_temp_dir() . '/gdeslon-cli-' . bin2hex(random_bytes(4)) . '.pdf';
        file_put_contents($this->attachment, "%PDF-1.4\nчек");
    }

    protected function tearDown(): void
    {
        @unlink($this->attachment);
    }

    public function testOrdersRequireSalesKeys(): void
    {
        foreach ([[], ['GDESLON_USER_ID' => '1234'], self::TOKEN] as $env) {
            $cli = new CliTester();
            self::assertSame(3, $cli->run(['orders'], $env));
            self::assertStringContainsString('GDESLON_API_KEY', $cli->stderr);
            self::assertSame(0, $cli->factoryCalls);
        }
    }

    public function testOrdersFiltersReachRequest(): void
    {
        $cli = new CliTester((new FakeHttpTransport())->willReturn(200, '[]'));

        self::assertSame(0, $cli->run(['orders', '--date-field=last-updated', '--until=2026-10-07', '--days=7', '--state=confirmed,paid', '--type=product',
            '--merchant=2573', '--sub-id=blog'], self::SALES));

        $request = $cli->transport->lastRequest();
        self::assertSame('Basic ' . base64_encode('1234:test-api-key'), $request->header('Authorization'));
        self::assertSame('{"last_updated_at":{"date":"2026-10-07","period":7},"merchant_id":2573,"state":[3,4],"type":0,"sub_id":"blog"}', $request->body());
        self::assertStringContainsString('Ничего не найдено.', $cli->stderr);
    }

    public function testOrdersTodayIsMoscow(): void
    {
        $cli = new CliTester((new FakeHttpTransport())->willReturn(200, '[]'), FrozenClock::at('2026-10-06T22:30:00Z'));

        self::assertSame(0, $cli->run(['orders'], self::SALES));
        self::assertSame('{"created_at":{"date":"2026-10-07","period":30}}', $cli->transport->lastRequest()->body());
    }

    public function testOrdersOutput(): void
    {
        $cli = new CliTester((new FakeHttpTransport())->willReturn(200, Fixtures::read('orders/orders-synthetic.json')));
        self::assertSame(0, $cli->run(['orders'], self::SALES));
        self::assertStringContainsString('150.00 RUB', $cli->stdout);
        self::assertStringContainsString('Магазин Пример', $cli->stdout);
        self::assertStringNotContainsString('test-api-key', $cli->stdout . $cli->stderr);

        $cli = new CliTester((new FakeHttpTransport())->willReturn(200, Fixtures::read('orders/orders-synthetic.json')));
        self::assertSame(0, $cli->run(['orders', '--format=json'], self::SALES));
        $data = json_decode($cli->stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsArray($data['orders']);
        self::assertCount(4, $data['orders']);
    }

    public function testOrdersErrors(): void
    {
        foreach ([[401, '{"detail":"Недопустимые имя пользователя или пароль."}', 3], [500, Fixtures::read('orders/error-500.html'), 1]] as [$status, $body, $code]) {
            $cli = new CliTester((new FakeHttpTransport())->willReturn($status, $body));
            self::assertSame($code, $cli->run(['orders'], self::SALES));
        }
        foreach ([['--days=0'], ['--days=3661'], ['--until=2026-02-30'], ['--state=foo'], ['--type=2'], ['--date-field=foo']] as $options) {
            $cli = new CliTester();
            self::assertSame(2, $cli->run(['orders', ...$options], self::SALES), implode(' ', $options));
            self::assertSame([], $cli->transport->requests());
        }
    }

    public function testLostOrdersList(): void
    {
        self::assertSame(3, (new CliTester())->run(['lost-orders']));

        $cli = new CliTester((new FakeHttpTransport())->willReturn(200, Fixtures::read('lost-orders/claims-synthetic.json')));
        self::assertSame(0, $cli->run(['lost-orders', 'list', '--merchant=2573', '--from=2026-09-01', '--until=2026-09-30', '--claim-state=in-work'], self::TOKEN));
        self::assertSame(
            'https://gdeslon.ru/api/v1/lost-orders/?merchant_id=2573&start_date=2026-09-01&end_date=2026-09-30&ticket_state=in_work',
            $cli->transport->lastRequest()->uri(),
        );
        self::assertSame('Bearer secret-token-0123', $cli->transport->lastRequest()->header('Authorization'));
        self::assertStringContainsString('GS123L', $cli->stdout);

        foreach ([['--from=2026-10-01', '--until=2026-09-01'], ['--claim-state=foo'], ['--order-status=foo']] as $options) {
            self::assertSame(2, (new CliTester())->run(['lost-orders', ...$options], self::TOKEN), implode(' ', $options));
        }

        $cli = new CliTester((new FakeHttpTransport())->willReturn(400, Fixtures::read('lost-orders/error-400-merchant.json')));
        self::assertSame(1, $cli->run(['lost-orders', '--merchant=1'], self::TOKEN));
        self::assertStringContainsString('merchant_id:', $cli->stderr);
    }

    public function testMalformedTokenIsAccessError(): void
    {
        foreach (['a b', 'токен-из-буфера'] as $token) {
            $cli = new CliTester();
            self::assertSame(3, $cli->run(['lost-orders'], ['GDESLON_API_TOKEN' => $token]), $token);
            self::assertStringContainsString('GDESLON_API_TOKEN', $cli->stderr);
            self::assertStringNotContainsString('Подробнее', $cli->stderr);
            self::assertSame([], $cli->transport->requests());
        }
    }

    public function testLostOrderShow(): void
    {
        $cli = new CliTester((new FakeHttpTransport())->willReturn(200, Fixtures::read('lost-orders/claim-synthetic.json')));
        self::assertSame(0, $cli->run(['lost-orders', 'show', '5796'], self::TOKEN));
        self::assertStringContainsString('2342lost', $cli->stdout);

        $cli = new CliTester((new FakeHttpTransport())->willReturn(404, Fixtures::read('lost-orders/error-404.json')));
        self::assertSame(1, $cli->run(['lost-orders', 'show', '5796'], self::TOKEN));
        self::assertStringContainsString('Заявки 5796 нет', $cli->stderr);

        self::assertSame(2, (new CliTester())->run(['lost-orders', 'show', '0'], self::TOKEN));
    }

    public function testSubmitValidatesBeforeAnyRequest(): void
    {
        $cli = new CliTester();
        self::assertSame(2, $cli->run(['lost-orders', 'submit', '--merchant=2573'], self::TOKEN));
        self::assertStringContainsString('--order-number', $cli->stderr);

        foreach ([['--order-date=2026-01-01'], ['--order-date=2026-10-08'], ['--attachment=/nonexistent/private/receipt.pdf'], ['--order-total=1.234']] as $override) {
            $cli = new CliTester();
            self::assertSame(2, $cli->run(['lost-orders', 'submit', ...$this->submitOptions($override), '--yes'], self::TOKEN), implode(' ', $override));
            self::assertSame([], $cli->transport->requests(), 'ни одного запроса');
            self::assertStringNotContainsString('/nonexistent/private', $cli->stderr, 'без локального пути');
        }

        self::assertSame(2, (new CliTester())->run(['lost-orders', 'submit', ...$this->submitOptions(), '--yes', '--dry-run'], self::TOKEN));
    }

    public function testSubmitRequiresConfirmation(): void
    {
        foreach (['', "no\n"] as $stdin) {
            $claims = new FakeLostOrderClaims();
            $cli = self::withClaims($claims);
            self::assertSame(1, $cli->run(['lost-orders', 'submit', ...$this->submitOptions()], self::TOKEN, $stdin));
            self::assertStringContainsString('РЕАЛЬНАЯ заявка', $cli->stderr);
            self::assertStringContainsString('Отменено', $cli->stderr);
            self::assertSame([], $claims->submitted);
        }

        $claims = new FakeLostOrderClaims();
        $cli = self::withClaims($claims);
        self::assertSame(0, $cli->run(['lost-orders', 'submit', ...$this->submitOptions()], self::TOKEN, "YES\n"));
        self::assertCount(1, $claims->submitted);
        self::assertStringContainsString('9001', $cli->stdout);

        $claims = new FakeLostOrderClaims();
        $cli = self::withClaims($claims);
        self::assertSame(0, $cli->run(['lost-orders', 'submit', ...$this->submitOptions(), '--yes', '--format=json'], self::TOKEN));
        self::assertStringNotContainsString('Введите yes', $cli->stderr);
        self::assertCount(1, $claims->submitted);
        self::assertStringContainsString('"claim"', $cli->stdout);
    }

    public function testSubmitDryRunAndDuplicates(): void
    {
        $claims = new FakeLostOrderClaims();
        $cli = self::withClaims($claims);
        self::assertSame(0, $cli->run(['lost-orders', 'submit', ...$this->submitOptions(), '--dry-run', '--format=json'], self::TOKEN));
        self::assertSame([], $claims->submitted);
        self::assertCount(1, $claims->criteria, 'дубли проверены');
        self::assertStringContainsString('"dry_run": true', $cli->stdout);

        $existing = (new FakeLostOrderClaims())->submit(new NewLostOrderClaim('GS123L', '2026-09-24', '1', 2573, ClaimAttachment::fromContents('r.pdf', "%PDF-1.4\n")));
        $claims = new FakeLostOrderClaims([$existing]);
        $cli = self::withClaims($claims);
        self::assertSame(5, $cli->run(['lost-orders', 'submit', ...$this->submitOptions(), '--yes'], self::TOKEN));
        self::assertStringContainsString('9001', $cli->stderr);
        self::assertStringContainsString('ожидает', $cli->stderr, 'статус существующей заявки');
        self::assertSame([], $claims->submitted);

        $claims = new FakeLostOrderClaims([$existing]);
        $cli = self::withClaims($claims);
        self::assertSame(0, $cli->run(['lost-orders', 'submit', ...$this->submitOptions(), '--yes', '--no-duplicate-check'], self::TOKEN));
        self::assertSame([], $claims->criteria, 'без проверки дублей — без запроса списка');
        self::assertCount(1, $claims->submitted);
    }

    public function testSubmitWhenDuplicatesCannotBeChecked(): void
    {
        $claims = Fixtures::read('lost-orders/claims-synthetic.json');
        $broken = substr($claims, 0, (int) strrpos($claims, ']')) . ', {"id": 5799, "order_id": "X1"}]';

        $cli = new CliTester((new FakeHttpTransport())->willReturn(200, $broken));
        self::assertSame(1, $cli->run(['lost-orders', 'submit', ...$this->submitOptions(), '--yes'], self::TOKEN));
        self::assertStringContainsString('--no-duplicate-check', $cli->stderr);
        self::assertSame([], self::posts($cli));

        // гонка: до вопроса список чистый, перед отправкой — уже с битой записью
        $cli = new CliTester((new FakeHttpTransport())->willReturn(200, '[]')->willReturn(200, $broken));
        self::assertSame(1, $cli->run(['lost-orders', 'submit', ...$this->submitOptions(), '--yes'], self::TOKEN));
        self::assertStringContainsString('--no-duplicate-check', $cli->stderr);
        self::assertStringNotContainsString('checkDuplicates', $cli->stderr);
        self::assertSame([], self::posts($cli));
    }

    public function testSubmitDryRunTable(): void
    {
        $claims = new FakeLostOrderClaims();
        $cli = self::withClaims($claims);

        self::assertSame(0, $cli->run(['lost-orders', 'submit', ...$this->submitOptions(), '--dry-run'], self::TOKEN));
        self::assertSame('', $cli->stdout);
        self::assertStringContainsString('--dry-run', $cli->stderr);
        self::assertStringNotContainsString('Введите yes', $cli->stderr);
        self::assertSame([], $claims->submitted);
    }

    public function testSubmitUnknownOutcome(): void
    {
        $transport = (new FakeHttpTransport())
            ->willReturn(200, '[]')
            ->willReturn(200, '[]')
            ->willThrow(new TimeoutException('таймаут', 'POST', 'https://gdeslon.ru/api/v1/lost-orders/', 28));
        $cli = new CliTester($transport);

        self::assertSame(4, $cli->run(['lost-orders', 'submit', ...$this->submitOptions(), '--yes'], self::TOKEN));
        self::assertStringContainsString('НЕ ПОВТОРЯЙТЕ', $cli->stderr);
        self::assertStringContainsString('gdeslon lost-orders list --merchant=2573', $cli->stderr);
        self::assertCount(1, array_filter($transport->requests(), static fn ($r): bool => $r->method() === 'POST'), 'POST ровно один');
        self::assertSame(120.0, $cli->config?->timeout(), 'таймаут создания по умолчанию');

        $claims = new FakeLostOrderClaims();
        $claims->failSubmitWith(new LostOrderClaimUnconfirmedException(new UnexpectedResponseException('битый'), 201, 9100));
        $cli = self::withClaims($claims);
        self::assertSame(4, $cli->run(['lost-orders', 'submit', ...$this->submitOptions(), '--yes', '--timeout=30'], self::TOKEN));
        self::assertStringContainsString('создана', $cli->stderr);
        self::assertStringContainsString('9100', $cli->stderr);
        self::assertSame(30.0, $cli->config?->timeout());
    }

    /**
     * Исход заявки известен — сбой вывода после отправки (полный диск, закрытый поток) не меняет код: 4 и 0 не
     * становятся 1, который скрипты повторяют. Сбой до отправки — код 1 и ничего не отправлено.
     */
    public function testSubmitOutcomeSurvivesOutputFailure(): void
    {
        $claims = new FakeLostOrderClaims();
        $claims->failSubmitWith(new LostOrderClaimUnconfirmedException(new TimeoutException('таймаут', 'POST', 'https://gdeslon.ru/api/v1/lost-orders/', 28)));
        self::assertSame(4, $this->runBroken($claims, ['--no-duplicate-check'], brokenErr: true));

        $claims = new FakeLostOrderClaims();
        self::assertSame(0, $this->runBroken($claims, ['--no-duplicate-check', '--format=json'], brokenOut: true, brokenErr: true));
        self::assertCount(1, $claims->submitted);

        $existing = (new FakeLostOrderClaims())->submit(new NewLostOrderClaim('GS123L', '2026-09-24', '1', 2573, ClaimAttachment::fromContents('r.pdf', "%PDF-1.4\n")));
        $claims = new FakeLostOrderClaims([$existing]);
        self::assertSame(5, $this->runBroken($claims, [], brokenErr: true, fromStart: true));
        self::assertSame([], $claims->submitted);

        $claims = new FakeLostOrderClaims();
        self::assertSame(1, $this->runBroken($claims, ['--no-duplicate-check'], brokenErr: true, fromStart: true), 'stderr недоступен до отправки');
        self::assertSame([], $claims->submitted);
    }

    public function testSubmitRejectedByApi(): void
    {
        $transport = (new FakeHttpTransport())
            ->willReturn(200, '[]')
            ->willReturn(200, '[]')
            ->willReturn(400, Fixtures::read('lost-orders/error-400-create-synthetic.json'));
        $cli = new CliTester($transport);

        self::assertSame(1, $cli->run(['lost-orders', 'submit', ...$this->submitOptions(), '--yes'], self::TOKEN));
        self::assertStringContainsString('order_date:', $cli->stderr);
        self::assertStringContainsString('attachment:', $cli->stderr);
    }

    /**
     * @param list<string> $override
     *
     * @return list<string>
     */
    private function submitOptions(array $override = []): array
    {
        $options = ['merchant' => '2573', 'order-number' => 'GS123L', 'order-date' => '2026-09-24', 'order-total' => '554.34', 'attachment' => $this->attachment];
        foreach ($override as $option) {
            [$name, $value] = explode('=', substr($option, 2), 2);
            $options[$name] = $value;
        }

        return array_map(static fn (string $name, string $value): string => '--' . $name . '=' . $value, array_keys($options), $options);
    }

    /**
     * @return list<mixed>
     */
    private static function posts(CliTester $cli): array
    {
        return array_values(array_filter($cli->transport->requests(), static fn ($r): bool => $r->method() === 'POST'));
    }

    /**
     * Запуск, в котором stdout и/или stderr отказывают с момента отправки заявки ($fromStart — сразу).
     *
     * @param list<string> $options
     */
    private function runBroken(FakeLostOrderClaims $claims, array $options, bool $brokenOut = false, bool $brokenErr = false, bool $fromStart = false): int
    {
        $clock = FrozenClock::at('2026-10-07T10:00:00Z');
        $memory = static function () {
            $stream = fopen('php://memory', 'r+b');
            self::assertIsResource($stream);

            return $stream;
        };
        $out = $brokenOut ? FailingStream::open() : $memory();
        $err = $brokenErr ? FailingStream::open() : $memory();
        FailingStream::$failing = $fromStart;
        $port = new class ($claims) implements LostOrderClaims {
            public function __construct(private readonly FakeLostOrderClaims $claims)
            {
            }

            public function find(LostOrderCriteria $criteria): LostOrderClaimList
            {
                return $this->claims->find($criteria);
            }

            public function get(LostOrderClaimId $id): ?LostOrderClaim
            {
                return $this->claims->get($id);
            }

            public function submit(NewLostOrderClaim $claim): LostOrderClaim
            {
                FailingStream::$failing = true;

                return $this->claims->submit($claim);
            }
        };
        $application = new Application(
            static fn () => new GdeSlon(new FakeHttpTransport(), null, null, null, null, $port, $clock),
            new Console($memory(), $out, $err),
            self::TOKEN,
            $clock,
        );

        try {
            return $application->run(['lost-orders', 'submit', ...$this->submitOptions(), '--yes', ...$options]);
        } finally {
            FailingStream::$failing = false;
        }
    }

    private static function withClaims(FakeLostOrderClaims $claims): CliTester
    {
        $clock = FrozenClock::at('2026-10-07T10:00:00Z');

        return new CliTester(clock: $clock, build: static fn () => new GdeSlon(new FakeHttpTransport(), null, null, null, null, $claims, $clock));
    }
}

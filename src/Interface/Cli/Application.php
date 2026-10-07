<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Cli;

use Webreboot\GdeSlon\Config;
use Webreboot\GdeSlon\Domain\Shared\Clock;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\GdeSlon;
use Webreboot\GdeSlon\Infrastructure\Cache\CacheStore;
use Webreboot\GdeSlon\Infrastructure\Cache\FileCacheStore;
use Webreboot\GdeSlon\Infrastructure\Clock\SystemClock;

/**
 * Консольная утилита gdeslon (bin/gdeslon). Разбирает командную строку, берёт ключи только из окружения или
 * `--env-file`, собирает Config под требования команды и вызывает фасад SDK. Данные — в stdout, ошибки — в stderr;
 * коды выхода — ExitCode. Публичный контракт — командная строка (docs/cli.md), а не этот класс.
 *
 * @internal
 */
final class Application
{
    private const FORMATS = ['table', 'json'];

    private const DEFAULT_TIMEOUT = 30.0;

    /** @var array<string, Command\Command> */
    private readonly array $commands;

    /**
     * @param \Closure(Config, ?CacheStore): GdeSlon $factory
     * @param array<string, string>                 $env
     */
    public function __construct(
        private readonly \Closure $factory,
        private readonly Console $console,
        #[\SensitiveParameter]
        private readonly array $env,
        private readonly Clock $clock,
    ) {
        $commands = [];
        foreach ([
            new Command\CategoriesCommand(),
            new Command\MerchantsListCommand(),
            new Command\MerchantShowCommand(),
            new Command\MerchantCategoriesCommand(),
            new Command\SearchCommand(),
            new Command\OrdersCommand(),
            new Command\LostOrdersListCommand(),
            new Command\LostOrderShowCommand(),
            new Command\LostOrderSubmitCommand(),
            new Command\CouponsListCommand(),
            new Command\CouponShowCommand(),
            new Command\CouponKindsCommand(),
            new Command\McpCommand(),
        ] as $command) {
            $commands[$command->name()] = $command;
        }
        $this->commands = $commands;
    }

    public static function create(): self
    {
        $in = fopen('php://stdin', 'rb');
        $out = fopen('php://stdout', 'wb');
        $err = fopen('php://stderr', 'wb');
        if ($in === false || $out === false || $err === false) {
            throw new \RuntimeException('Не удалось открыть стандартные потоки');
        }
        $env = getenv();
        $clock = new SystemClock();

        return new self(
            static fn (Config $config, ?CacheStore $cache): GdeSlon => GdeSlon::create($config, null, $cache, $clock),
            new Console($in, $out, $err),
            $env,
            $clock,
        );
    }

    /**
     * @param list<string> $argv аргументы без имени программы
     */
    public function run(array $argv): int
    {
        $console = $this->console;
        $command = null;
        try {
            [$command, $words, $helpTopic] = $this->resolve($argv);
            $input = ArgvParser::parse($argv, [...self::globalOptions(), ...($command?->options() ?? [])]);

            if ($input->flag('version')) {
                $console->out('gdeslon-api ' . GdeSlon::VERSION . "\n");

                return ExitCode::OK;
            }
            if ($command === null) {
                $console->out($this->usage());

                return ExitCode::OK;
            }
            if ($helpTopic || $input->flag('help')) {
                $console->out($this->commandHelp($command));

                return ExitCode::OK;
            }

            $arguments = array_slice($input->positionals(), $words);
            $format = $input->value('format') ?? 'table';
            if (!in_array($format, self::FORMATS, true)) {
                throw new UsageException(sprintf('«--format»: допустимые значения — %s; получено «%s»', implode(', ', self::FORMATS), $format));
            }
            $timeout = self::timeout($input->value('timeout')) ?? $command->defaultTimeout();
            if ($input->flag('no-cache') && $input->has('cache-dir')) {
                throw new UsageException('«--no-cache» и «--cache-dir» вместе не указываются');
            }

            $environment = new Environment($this->env, $input->has('env-file') ? EnvFile::load((string) $input->value('env-file')) : []);
            $console = $console->withSecrets($environment->secrets());
            $environment->assertCredentials($command->credentials());

            $config = $this->config($command->credentials(), $environment, $timeout);
            $cacheDir = $input->flag('no-cache') ? null : $input->value('cache-dir') ?? $environment->cacheDirectory();
            $cache = $cacheDir === null ? null : new FileCacheStore($cacheDir);

            $factory = $this->factory;
            $context = new CommandContext(static fn (): GdeSlon => $factory($config, $cache), $console, $this->clock, $format === 'json', $environment);

            return $command->execute($input, $arguments, $context);
        } catch (\Throwable $error) {
            [$code, $text] = ErrorReporter::report($error);
            if ($code === ExitCode::USAGE) {
                $text .= sprintf("Подробнее: gdeslon help%s\n", $command === null ? '' : ' ' . $command->name());
            }
            try {
                $console->err($text);
            } catch (OutputFailedException) {
                // stderr недоступен — остаётся код выхода
            }

            return $code;
        }
    }

    /**
     * Команда по первым позиционным словам (глобальные опции могут стоять перед ней).
     *
     * @param list<string> $argv
     *
     * @return array{Command\Command|null, int, bool} команда (null — общая справка), сколько слов занимает её имя
     *                                               и запрошена ли справка по ней («help <команда>»)
     *
     * @throws UsageException
     */
    private function resolve(array $argv): array
    {
        $words = [];
        // значения опций в форме «--opt value» — не слова команды («merchants --search show»); имена опций со значением
        // у всех команд разные с флагами, поэтому достаточно общего списка
        $valueOptions = [];
        foreach ([self::globalOptions(), ...array_map(static fn (Command\Command $c): array => $c->options(), array_values($this->commands))] as $options) {
            foreach ($options as $option) {
                if ($option->kind !== Option::FLAG) {
                    $valueOptions[] = '--' . $option->name;
                }
            }
        }
        for ($i = 0, $count = count($argv); $i < $count && count($words) < 3; $i++) {
            $token = $argv[$i];
            if ($token === '--') {
                break;
            }
            if (str_starts_with($token, '-')) {
                if (in_array($token, $valueOptions, true)) {
                    $i++;
                }

                continue;
            }
            $words[] = $token;
        }

        if ($words === []) {
            return [null, 0, false];
        }
        if ($words[0] === 'help') {
            $topic = array_slice($words, 1);
            if ($topic === []) {
                return [null, 1, true];
            }
            [$command, $length] = $this->find($topic) ?? throw new UsageException(sprintf('Нет команды «%s»', implode(' ', $topic)));

            return [$command, 1 + $length, true];
        }
        [$command, $length] = $this->find($words) ?? throw new UsageException(sprintf('Неизвестная команда «%s»', $words[0]));

        return [$command, $length, false];
    }

    /**
     * «группа подкоманда», затем «группа» (у групп без подкоманды — «группа list»).
     *
     * @param non-empty-list<string> $words
     *
     * @return array{Command\Command, int}|null
     */
    private function find(array $words): ?array
    {
        if (isset($words[1], $this->commands[$words[0] . ' ' . $words[1]])) {
            return [$this->commands[$words[0] . ' ' . $words[1]], 2];
        }
        $command = $this->commands[$words[0]] ?? $this->commands[$words[0] . ' list'] ?? null;

        return $command === null ? null : [$command, 1];
    }

    /**
     * Все заданные ключи — в Config; неверная пара ключей по продажам мешает только командам, которым она нужна.
     */
    private function config(Credentials $credentials, Environment $environment, ?float $timeout): Config
    {
        $timeout ??= self::DEFAULT_TIMEOUT;
        $sales = $environment->userId() !== null && $environment->apiKey() !== null;
        try {
            return new Config(
                timeout: $timeout,
                apiToken: $environment->token(),
                userId: $sales ? $environment->userId() : null,
                apiKey: $sales ? $environment->apiKey() : null,
            );
        } catch (InvalidArgumentException $e) {
            if (!$sales || $credentials === Credentials::SalesKeys) {
                throw new MissingCredentialsException('Ключи не приняты: ' . $e->getMessage(), 0, $e);
            }

            return $this->config(Credentials::None, new Environment(array_filter(
                ['GDESLON_API_TOKEN' => $environment->token()],
                static fn (?string $value): bool => $value !== null,
            )), $timeout);
        }
    }

    private static function timeout(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }
        if (preg_match('/^\d{1,6}(\.\d{1,3})?\z/', $value) !== 1 || (float) $value <= 0) {
            throw new UsageException(sprintf('«--timeout»: ожидалось положительное число секунд, получено «%s»', $value));
        }

        return (float) $value;
    }

    /**
     * @return list<Option>
     */
    public static function globalOptions(): array
    {
        return [
            Option::value('format', 'формат вывода: table (по умолчанию) или json', 'table|json'),
            Option::value('env-file', 'читать GDESLON_* из файла (окружение процесса важнее)', 'PATH'),
            Option::value('cache-dir', 'каталог кэша категорий и магазинов', 'DIR'),
            Option::flag('no-cache', 'без кэша'),
            Option::value('timeout', 'таймаут запроса, секунды', 'SEC'),
            Option::flag('help', 'справка', 'h'),
            Option::flag('version', 'версия', 'V'),
        ];
    }

    private function usage(): string
    {
        $text = "gdeslon — API вебмастера «Где Слон?» из командной строки (gdeslon-api " . GdeSlon::VERSION . ")\n\n"
            . "Использование: gdeslon [опции] <команда> [аргументы] [опции]\n\nКоманды:\n";
        $width = 0;
        foreach ($this->commands as $command) {
            $width = max($width, strlen($command->name() . ' ' . $command->arguments()));
        }
        foreach ($this->commands as $command) {
            $text .= sprintf("  %-{$width}s  %s\n", trim($command->name() . ' ' . $command->arguments()), $command->summary());
        }
        $text .= "  help [<команда>]  справка по команде\n\nОбщие опции:\n" . self::optionsHelp(self::globalOptions())
            . "\nКлючи — только из окружения (или --env-file), не из аргументов:\n"
            . "  GDESLON_API_TOKEN                  токен XML API (https://gdeslon.ru/api_settings/xml)\n"
            . "  GDESLON_USER_ID, GDESLON_API_KEY   ключи API по продажам (https://gdeslon.ru/api_settings/orders)\n"
            . "\nКоды выхода: 0 — успех, 1 — ошибка, 2 — неверный вызов, 3 — нет ключей или доступа,\n"
            . "  4 — заявка не подтверждена (НЕ повторять), 5 — заявка уже есть.\n";

        return $text;
    }

    private function commandHelp(Command\Command $target): string
    {
        return sprintf("gdeslon %s\n\n%s\n", trim($target->name() . ' ' . $target->arguments()), $target->summary())
            . ($target->help() === '' ? '' : "\n" . $target->help() . "\n")
            . ($target->options() === [] ? '' : "\nОпции:\n" . self::optionsHelp($target->options()))
            . "\nОбщие опции:\n" . self::optionsHelp(self::globalOptions());
    }

    /**
     * @param list<Option> $options
     */
    private static function optionsHelp(array $options): string
    {
        $width = max([0, ...array_map(static fn (Option $o): int => strlen($o->usage()), $options)]);
        $text = '';
        foreach ($options as $option) {
            $text .= sprintf("  %-{$width}s  %s\n", $option->usage(), $option->description);
        }

        return $text;
    }
}

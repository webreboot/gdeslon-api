<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webreboot\GdeSlon\Domain\Catalog\CategoryNotFoundException;
use Webreboot\GdeSlon\Domain\Catalog\MerchantNotFoundException;
use Webreboot\GdeSlon\Domain\Claims\DuplicateLostOrderClaimException;
use Webreboot\GdeSlon\Domain\Claims\LostOrderClaimUnconfirmedException;
use Webreboot\GdeSlon\Domain\Claims\LostOrderValidationException;
use Webreboot\GdeSlon\Domain\Promo\CouponCriteriaRejectedException;
use Webreboot\GdeSlon\Exception\AuthenticationException;
use Webreboot\GdeSlon\Exception\GdeSlonException;
use Webreboot\GdeSlon\Exception\HttpException;
use Webreboot\GdeSlon\Exception\InvalidArgumentException;
use Webreboot\GdeSlon\Exception\TimeoutException;
use Webreboot\GdeSlon\Exception\TransportException;
use Webreboot\GdeSlon\Exception\UnexpectedResponseException;
use Webreboot\GdeSlon\Interface\Cli\MissingCredentialsException;
use Webreboot\GdeSlon\Interface\Cli\OutputFailedException;
use Webreboot\GdeSlon\Interface\Cli\UsageException;
use Webreboot\GdeSlon\Interface\Postback\InvalidPostbackException;
use Webreboot\GdeSlon\Interface\Postback\PostbackAuthenticationException;
use Webreboot\GdeSlon\Interface\Postback\PostbackException;

final class ExceptionHierarchyTest extends TestCase
{
    /**
     * @param class-string $class
     */
    #[DataProvider('packageExceptions')]
    public function testEveryPackageExceptionIsGdeSlonException(string $class): void
    {
        self::assertTrue((new \ReflectionClass($class))->implementsInterface(GdeSlonException::class), $class);
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function packageExceptions(): iterable
    {
        foreach ([
            InvalidArgumentException::class,
            TransportException::class,
            TimeoutException::class,
            HttpException::class,
            AuthenticationException::class,
            UnexpectedResponseException::class,
            CategoryNotFoundException::class,
            MerchantNotFoundException::class,
            PostbackException::class,
            PostbackAuthenticationException::class,
            InvalidPostbackException::class,
            LostOrderValidationException::class,
            DuplicateLostOrderClaimException::class,
            LostOrderClaimUnconfirmedException::class,
            CouponCriteriaRejectedException::class,
            UsageException::class,
            MissingCredentialsException::class,
            OutputFailedException::class,
        ] as $class) {
            yield $class => [$class];
        }
    }

    public function testSpecialisations(): void
    {
        self::assertTrue(self::extends(TimeoutException::class, TransportException::class));
        self::assertTrue(self::extends(AuthenticationException::class, HttpException::class));
        self::assertTrue(self::extends(InvalidArgumentException::class, \InvalidArgumentException::class));
        self::assertTrue(self::extends(CategoryNotFoundException::class, \OutOfBoundsException::class));
        self::assertTrue(self::extends(MerchantNotFoundException::class, \OutOfBoundsException::class));
        self::assertTrue(self::extends(PostbackException::class, \RuntimeException::class));
        self::assertTrue(self::extends(PostbackAuthenticationException::class, PostbackException::class));
        self::assertTrue(self::extends(InvalidPostbackException::class, PostbackException::class));
        self::assertTrue(self::extends(LostOrderValidationException::class, HttpException::class));
        self::assertTrue(self::extends(CouponCriteriaRejectedException::class, HttpException::class));
        self::assertTrue(self::extends(UsageException::class, \InvalidArgumentException::class));
    }

    public function testTransportExceptionCarriesRequestAndCurlCode(): void
    {
        $previous = new \RuntimeException('raw');
        $e = new TimeoutException('GET https://x/: таймаут', 'GET', 'https://x/', 28, $previous);

        self::assertSame('GET', $e->method());
        self::assertSame('https://x/', $e->url());
        self::assertSame(28, $e->curlErrorCode());
        self::assertSame($previous, $e->getPrevious());
        self::assertNull((new TransportException('m', 'GET', 'https://x/'))->curlErrorCode());
    }

    public function testHttpExceptionCarriesResponse(): void
    {
        $e = new AuthenticationException('GET https://x/: HTTP 401', 401, 'GET', 'https://x/', '{"detail":"нет"}');

        self::assertSame(401, $e->statusCode());
        self::assertSame(401, $e->getCode());
        self::assertSame('GET', $e->method());
        self::assertSame('https://x/', $e->url());
        self::assertSame('{"detail":"нет"}', $e->responseSnippet());
    }

    /**
     * Через Reflection: phpstan не сворачивает проверку в константу, как is_subclass_of().
     *
     * @param class-string $class
     * @param class-string $parent
     */
    private static function extends(string $class, string $parent): bool
    {
        return (new \ReflectionClass($class))->isSubclassOf($parent);
    }
}

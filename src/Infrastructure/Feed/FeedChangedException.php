<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Infrastructure\Feed;

use Webreboot\GdeSlon\Exception\GdeSlonException;

/** @internal */
final class FeedChangedException extends \RuntimeException implements GdeSlonException
{
}

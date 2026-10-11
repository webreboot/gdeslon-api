<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Tests\Support;

use Webreboot\GdeSlon\Domain\Catalog\MerchantList;
use Webreboot\GdeSlon\Domain\Catalog\MerchantRepository;

final class FakeMerchantRepository implements MerchantRepository
{
    private int $calls = 0;

    private ?bool $lastPublic = null;

    private ?bool $lastRefresh = null;

    public function __construct(private readonly MerchantList $list)
    {
    }

    public function all(bool $public = false, bool $refresh = false): MerchantList
    {
        $this->calls++;
        $this->lastPublic = $public;
        $this->lastRefresh = $refresh;

        return $this->list;
    }

    public function calls(): int
    {
        return $this->calls;
    }

    public function lastPublic(): ?bool
    {
        return $this->lastPublic;
    }

    public function lastRefresh(): ?bool
    {
        return $this->lastRefresh;
    }
}

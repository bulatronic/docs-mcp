<?php

declare(strict_types=1);

namespace DocsMcp\Tests\Support;

use DateTimeImmutable;
use DocsMcp\Application\Time\Clock;

final readonly class FixedClock implements Clock
{
    public function __construct(
        private DateTimeImmutable $now,
    ) {}

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}

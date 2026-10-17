<?php

declare(strict_types=1);

namespace DocsMcp\Infrastructure\Google;

/**
 * Injectable delay for retry backoff (no-op in unit tests).
 */
interface Sleeper
{
    public function sleep(float $seconds): void;
}

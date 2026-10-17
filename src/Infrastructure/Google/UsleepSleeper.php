<?php

declare(strict_types=1);

namespace DocsMcp\Infrastructure\Google;

final class UsleepSleeper implements Sleeper
{
    public function sleep(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        usleep((int) round($seconds * 1_000_000));
    }
}

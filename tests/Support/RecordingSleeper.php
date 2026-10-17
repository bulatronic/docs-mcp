<?php

declare(strict_types=1);

namespace DocsMcp\Tests\Support;

use DocsMcp\Infrastructure\Google\Sleeper;

final class RecordingSleeper implements Sleeper
{
    /** @var list<float> */
    public private(set) array $sleeps = [];

    public function sleep(float $seconds): void
    {
        $this->sleeps[] = $seconds;
    }
}

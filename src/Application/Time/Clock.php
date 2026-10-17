<?php

declare(strict_types=1);

namespace DocsMcp\Application\Time;

use DateTimeImmutable;

interface Clock
{
    public function now(): DateTimeImmutable;
}

<?php

declare(strict_types=1);

namespace DocsMcp\Domain\Error;

final class WriteDisabled extends DocsError
{
    public function __construct()
    {
        parent::__construct('Document writes are disabled');
    }
}

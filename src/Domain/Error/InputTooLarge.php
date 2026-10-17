<?php

declare(strict_types=1);

namespace DocsMcp\Domain\Error;

final class InputTooLarge extends DocsError
{
    public function __construct()
    {
        parent::__construct('Input exceeds the configured size limit');
    }
}

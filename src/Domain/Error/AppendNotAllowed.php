<?php

declare(strict_types=1);

namespace DocsMcp\Domain\Error;

final class AppendNotAllowed extends DocsError
{
    public function __construct()
    {
        parent::__construct('Append is not allowed for this document');
    }
}

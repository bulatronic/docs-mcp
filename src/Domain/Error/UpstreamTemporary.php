<?php

declare(strict_types=1);

namespace DocsMcp\Domain\Error;

final class UpstreamTemporary extends DocsError
{
    public function __construct()
    {
        parent::__construct('Upstream service temporarily unavailable');
    }
}

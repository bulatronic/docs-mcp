<?php

declare(strict_types=1);

namespace DocsMcp\Domain\Error;

final class UpstreamRejected extends DocsError
{
    public function __construct(string $message = 'Upstream service rejected the request')
    {
        parent::__construct($message);
    }
}

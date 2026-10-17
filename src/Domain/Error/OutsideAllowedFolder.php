<?php

declare(strict_types=1);

namespace DocsMcp\Domain\Error;

final class OutsideAllowedFolder extends DocsError
{
    public function __construct()
    {
        parent::__construct(PublicErrorMessage::NOT_FOUND_OR_OUTSIDE_ROOT);
    }
}

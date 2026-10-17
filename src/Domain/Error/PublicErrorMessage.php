<?php

declare(strict_types=1);

namespace DocsMcp\Domain\Error;

/**
 * Public MCP-facing messages that must not leak whether an item was missing or outside root.
 */
final class PublicErrorMessage
{
    public const string NOT_FOUND_OR_OUTSIDE_ROOT = 'Документ не найден или вне разрешённой папки';

    private function __construct() {}
}

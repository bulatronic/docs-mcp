<?php

declare(strict_types=1);

namespace DocsMcp\Infrastructure\Google;

/**
 * Escapes literals for Google Drive query language (q= parameter).
 */
final class DriveQueryEscaper
{
    private function __construct() {}

    /**
     * Escapes \ and ' for a single-quoted Drive query literal.
     */
    public static function escapeLiteral(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }
}

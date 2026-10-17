<?php

declare(strict_types=1);

namespace DocsMcp\Application\Text;

/**
 * Normalizes append payloads: CRLF → LF, drops control chars except \\n and \\t.
 */
final class AppendTextNormalizer
{
    private function __construct() {}

    public static function normalize(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);

        // Remove control characters (Unicode "C" category) except LF and TAB.
        $cleaned = preg_replace('/[^\P{C}\n\t]/u', '', $value);

        return is_string($cleaned) ? $cleaned : '';
    }
}

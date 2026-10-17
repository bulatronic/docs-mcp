<?php

declare(strict_types=1);

namespace DocsMcp\Domain;

/**
 * Accepted shape for folder and document ids that cross the process boundary.
 */
final readonly class ResourceId
{
    public const string PATTERN = '/^[A-Za-z0-9_-]{10,}$/';

    private function __construct() {}

    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }
}

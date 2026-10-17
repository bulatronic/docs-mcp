<?php

declare(strict_types=1);

namespace DocsMcp\Application\Drive;

use InvalidArgumentException;

/**
 * Validates a Drive name substring used for listing filters.
 */
final class DriveNameQuery
{
    public const int MAX_LENGTH = 100;

    private function __construct() {}

    /**
     * @return string Empty string means "no name filter"
     */
    public static function normalize(?string $query): string
    {
        if ($query === null) {
            return '';
        }

        $trimmed = trim($query);
        if ($trimmed === '') {
            return '';
        }

        if (mb_strlen($trimmed) > self::MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'query must be at most %d characters',
                self::MAX_LENGTH,
            ));
        }

        return $trimmed;
    }
}

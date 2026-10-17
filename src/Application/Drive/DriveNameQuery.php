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

        // Reject control characters so they cannot break or inject into Drive q=.
        if (preg_match('/[\p{C}]/u', $trimmed) === 1) {
            throw new InvalidArgumentException('query must not contain control characters');
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

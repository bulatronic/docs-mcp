<?php

declare(strict_types=1);

namespace DocsMcp\Domain\Drive;

use InvalidArgumentException;

/**
 * Shared value object for Google Drive document and folder ids.
 */
final readonly class DriveId
{
    public const string PATTERN = '/^[A-Za-z0-9_-]{10,}$/';

    public function __construct(public string $value)
    {
        if (!self::isValid($value)) {
            throw new InvalidArgumentException('Invalid Drive id');
        }
    }

    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}

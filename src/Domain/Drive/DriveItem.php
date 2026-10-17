<?php

declare(strict_types=1);

namespace DocsMcp\Domain\Drive;

use DateTimeImmutable;

final readonly class DriveItem
{
    /**
     * @param list<DriveId> $parents
     */
    public function __construct(
        public DriveId $id,
        public string $name,
        public string $mimeType,
        public array $parents,
        public bool $trashed,
        public ?DateTimeImmutable $modifiedTime,
    ) {}
}

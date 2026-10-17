<?php

declare(strict_types=1);

namespace DocsMcp\Domain\Drive;

use DateTimeImmutable;

final readonly class DocumentListItem
{
    public function __construct(
        public DriveId $id,
        public string $name,
        public ?DateTimeImmutable $modifiedTime,
        public string $path,
    ) {}
}

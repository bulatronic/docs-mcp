<?php

declare(strict_types=1);

namespace DocsMcp\Domain\Drive;

use DateTimeImmutable;

final readonly class Document
{
    public function __construct(
        public DriveId $id,
        public string $title,
        public string $text,
        public ?DateTimeImmutable $modifiedTime,
    ) {}
}

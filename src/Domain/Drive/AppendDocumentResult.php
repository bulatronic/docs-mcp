<?php

declare(strict_types=1);

namespace DocsMcp\Domain\Drive;

use DateTimeImmutable;

final readonly class AppendDocumentResult
{
    public function __construct(
        public DriveId $documentId,
        public int $appendedChars,
        public ?DateTimeImmutable $modifiedTime,
    ) {}
}

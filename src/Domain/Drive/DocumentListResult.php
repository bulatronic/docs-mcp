<?php

declare(strict_types=1);

namespace DocsMcp\Domain\Drive;

final readonly class DocumentListResult
{
    /**
     * @param list<DocumentListItem> $documents
     */
    public function __construct(
        public array $documents,
    ) {}
}

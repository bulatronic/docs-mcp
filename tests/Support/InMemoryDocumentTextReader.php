<?php

declare(strict_types=1);

namespace DocsMcp\Tests\Support;

use DocsMcp\Domain\Drive\Document;
use DocsMcp\Domain\Drive\DriveId;
use DocsMcp\Domain\Error\ItemNotFound;
use DocsMcp\Ports\DocumentTextReader;

final class InMemoryDocumentTextReader implements DocumentTextReader
{
    /** @var array<string, Document> */
    private array $documents = [];

    /**
     * @param list<Document> $documents
     */
    public function __construct(array $documents = [])
    {
        foreach ($documents as $document) {
            $this->documents[$document->id->value] = $document;
        }
    }

    public function add(Document $document): void
    {
        $this->documents[$document->id->value] = $document;
    }

    public function read(DriveId $documentId): Document
    {
        if (!isset($this->documents[$documentId->value])) {
            throw new ItemNotFound();
        }

        return $this->documents[$documentId->value];
    }
}

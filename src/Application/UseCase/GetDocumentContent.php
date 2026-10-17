<?php

declare(strict_types=1);

namespace DocsMcp\Application\UseCase;

use DocsMcp\Application\Policy\RootFolderPolicy;
use DocsMcp\Domain\Drive\DocumentContentResult;
use DocsMcp\Domain\Drive\DriveId;
use DocsMcp\Domain\Error\ItemNotFound;
use DocsMcp\Domain\Error\OutsideAllowedFolder;
use DocsMcp\Ports\DocumentTextReader;
use InvalidArgumentException;

final class GetDocumentContent
{
    public function __construct(
        private DocumentTextReader $textReader,
        private RootFolderPolicy $policy,
        private int $maxReadChars,
    ) {
        if ($this->maxReadChars < 1) {
            throw new InvalidArgumentException('maxReadChars must be >= 1');
        }
    }

    /**
     * @throws InvalidArgumentException
     * @throws ItemNotFound
     * @throws OutsideAllowedFolder
     */
    public function execute(string $documentId, ?int $maxChars = null): DocumentContentResult
    {
        $trimmed = trim($documentId);
        if (!DriveId::isValid($trimmed)) {
            throw new InvalidArgumentException('document_id is not a valid Drive id');
        }

        $id = new DriveId($trimmed);
        $item = $this->policy->assertInsideRoot($id);
        $this->policy->assertIsDocument($item);

        $limit = $maxChars ?? $this->maxReadChars;
        if ($limit < 1) {
            throw new InvalidArgumentException('max_chars must be >= 1');
        }
        $limit = min($limit, $this->maxReadChars);

        $document = $this->textReader->read($id);
        $totalChars = mb_strlen($document->text);
        $truncated = $totalChars > $limit;
        $content = $truncated ? mb_substr($document->text, 0, $limit) : $document->text;

        return new DocumentContentResult(
            documentId: $document->id,
            title: $document->title,
            modifiedTime: $document->modifiedTime ?? $item->modifiedTime,
            truncated: $truncated,
            totalChars: $totalChars,
            content: $content,
        );
    }
}

<?php

declare(strict_types=1);

namespace DocsMcp\Infrastructure\Google;

use DocsMcp\Domain\Drive\DriveId;
use DocsMcp\Ports\DocumentAppender;

/**
 * Thin adapter: appends plain text via the allowlisted Docs batchUpdate insertText.
 * Write policy (flag, allowlist, size) lives in Application\UseCase\AppendToDocument.
 */
final readonly class GoogleDocumentAppender implements DocumentAppender
{
    public function __construct(
        private GoogleHttpGateway $gateway,
    ) {}

    public function appendText(DriveId $documentId, string $text): void
    {
        $this->gateway->insertTextAtEnd($documentId, $text);
    }
}

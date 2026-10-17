<?php

declare(strict_types=1);

namespace DocsMcp\Infrastructure\Google;

use DocsMcp\Domain\Drive\Document;
use DocsMcp\Domain\Drive\DriveId;
use DocsMcp\Domain\Error\UpstreamRejected;
use DocsMcp\Ports\DocumentTextReader;

final readonly class GoogleDocumentTextReader implements DocumentTextReader
{
    public function __construct(
        private GoogleHttpGateway $gateway,
        private DocumentPlainTextExtractor $extractor = new DocumentPlainTextExtractor(),
    ) {}

    public function read(DriveId $documentId): Document
    {
        $payload = $this->gateway->getDocument($documentId);

        $title = $payload['title'] ?? '';
        if (!is_string($title)) {
            $title = '';
        }

        $idRaw = $payload['documentId'] ?? $documentId->value;
        if (!is_string($idRaw) || !DriveId::isValid($idRaw)) {
            throw new UpstreamRejected('Document payload is missing a valid id');
        }

        // Document tabs are not supported — extractor reads body.content only.
        $text = $this->extractor->extract($payload);

        return new Document(
            id: new DriveId($idRaw),
            title: $title,
            text: $text,
            modifiedTime: null,
        );
    }
}

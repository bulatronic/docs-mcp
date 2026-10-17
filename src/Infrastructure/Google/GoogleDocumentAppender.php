<?php

declare(strict_types=1);

namespace DocsMcp\Infrastructure\Google;

use DocsMcp\Domain\Drive\DriveId;
use DocsMcp\Domain\Error\AppendNotAllowed;
use DocsMcp\Domain\Error\InputTooLarge;
use DocsMcp\Domain\Error\WriteDisabled;
use DocsMcp\Infrastructure\Config\DocsConfig;
use DocsMcp\Ports\DocumentAppender;

final readonly class GoogleDocumentAppender implements DocumentAppender
{
    public function __construct(
        private GoogleHttpGateway $gateway,
        private DocsConfig $config,
    ) {}

    public function appendText(DriveId $documentId, string $text): void
    {
        if (!$this->config->writeEnabled) {
            throw new WriteDisabled();
        }

        if (!in_array($documentId->value, $this->config->appendAllowedIds, true)) {
            throw new AppendNotAllowed();
        }

        if ($text === '' || mb_strlen($text) > $this->config->maxAppendChars) {
            throw new InputTooLarge();
        }

        $this->gateway->insertTextAtEnd($documentId, $text);
    }
}

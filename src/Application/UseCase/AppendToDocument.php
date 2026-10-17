<?php

declare(strict_types=1);

namespace DocsMcp\Application\UseCase;

use DocsMcp\Application\Policy\RootFolderPolicy;
use DocsMcp\Application\Text\AppendTextNormalizer;
use DocsMcp\Application\Time\Clock;
use DocsMcp\Domain\Drive\AppendDocumentResult;
use DocsMcp\Domain\Drive\DriveId;
use DocsMcp\Domain\Error\AppendNotAllowed;
use DocsMcp\Domain\Error\InputTooLarge;
use DocsMcp\Domain\Error\ItemNotFound;
use DocsMcp\Domain\Error\OutsideAllowedFolder;
use DocsMcp\Domain\Error\UpstreamRejected;
use DocsMcp\Domain\Error\UpstreamTemporary;
use DocsMcp\Domain\Error\WriteDisabled;
use DocsMcp\Ports\DocumentAppender;
use DocsMcp\Ports\DriveMetadataReader;
use InvalidArgumentException;

final class AppendToDocument
{
    private const int MAX_HEADING_CHARS = 200;

    /**
     * @param list<string> $appendAllowedIds
     */
    public function __construct(
        private DocumentAppender $appender,
        private DriveMetadataReader $metadataReader,
        private RootFolderPolicy $policy,
        private Clock $clock,
        private bool $writeEnabled,
        private array $appendAllowedIds,
        private int $maxAppendChars,
    ) {
        if ($this->maxAppendChars < 1) {
            throw new InvalidArgumentException('maxAppendChars must be >= 1');
        }
    }

    /**
     * @throws WriteDisabled
     * @throws AppendNotAllowed
     * @throws InputTooLarge
     * @throws InvalidArgumentException
     * @throws ItemNotFound
     * @throws OutsideAllowedFolder
     * @throws UpstreamTemporary
     * @throws UpstreamRejected
     */
    public function execute(string $documentId, string $text, ?string $heading = null): AppendDocumentResult
    {
        if (!$this->writeEnabled) {
            throw new WriteDisabled();
        }

        $trimmedId = trim($documentId);
        if (!DriveId::isValid($trimmedId)) {
            throw new InvalidArgumentException('document_id is not a valid Drive id');
        }

        if (!in_array($trimmedId, $this->appendAllowedIds, true)) {
            throw new AppendNotAllowed();
        }

        $id = new DriveId($trimmedId);
        $item = $this->policy->assertInsideRoot($id);
        $this->policy->assertIsDocument($item);

        $normalizedText = AppendTextNormalizer::normalize($text);
        if ($normalizedText === '') {
            throw new InvalidArgumentException('text must not be empty');
        }
        if (mb_strlen($normalizedText) > $this->maxAppendChars) {
            throw new InputTooLarge();
        }

        $normalizedHeading = null;
        if ($heading !== null) {
            $normalizedHeading = AppendTextNormalizer::normalize($heading);
            if ($normalizedHeading === '') {
                $normalizedHeading = null;
            } elseif (mb_strlen($normalizedHeading) > self::MAX_HEADING_CHARS) {
                throw new InvalidArgumentException(sprintf(
                    'heading must be at most %d characters',
                    self::MAX_HEADING_CHARS,
                ));
            }
        }

        $block = $this->buildBlock($normalizedText, $normalizedHeading);
        $this->appender->appendText($id, $block);

        $updated = $this->metadataReader->getItem($id);

        return new AppendDocumentResult(
            documentId: $id,
            appendedChars: mb_strlen($block),
            modifiedTime: $updated->modifiedTime,
        );
    }

    private function buildBlock(string $text, ?string $heading): string
    {
        $block = "\n";
        if ($heading !== null) {
            $block .= $this->clock->now()->format('Y-m-d H:i') . ' — ' . $heading . "\n";
        }
        $block .= $text . "\n";

        return $block;
    }
}

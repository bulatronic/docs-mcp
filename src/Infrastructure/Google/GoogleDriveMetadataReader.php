<?php

declare(strict_types=1);

namespace DocsMcp\Infrastructure\Google;

use DocsMcp\Domain\Drive\DriveId;
use DocsMcp\Domain\Drive\DriveItem;
use DocsMcp\Domain\Drive\DriveMimeType;
use DocsMcp\Ports\DriveMetadataReader;
use InvalidArgumentException;

final readonly class GoogleDriveMetadataReader implements DriveMetadataReader
{
    /** @var list<string> */
    private const array ALLOWED_LIST_MIME_TYPES = [
        DriveMimeType::DOCUMENT,
        DriveMimeType::FOLDER,
    ];

    public function __construct(
        private GoogleHttpGateway $gateway,
        private DriveItemMapper $mapper = new DriveItemMapper(),
    ) {}

    public function getItem(DriveId $id): DriveItem
    {
        return $this->mapper->fromFile($this->gateway->getFileMetadata($id));
    }

    public function listChildren(
        DriveId $folderId,
        int $limit,
        ?string $nameContains = null,
        ?string $mimeType = null,
    ): array {
        // Ensure the folder exists (search alone returns [] for unknown parents).
        $this->getItem($folderId);

        // folderId is a validated DriveId ([A-Za-z0-9_-]+), safe inside single quotes.
        $q = sprintf("'%s' in parents and trashed=false", $folderId->value);

        if ($mimeType !== null && $mimeType !== '') {
            if (!in_array($mimeType, self::ALLOWED_LIST_MIME_TYPES, true)) {
                throw new InvalidArgumentException('mimeType is not allowlisted for listChildren');
            }
            $q .= sprintf(" and mimeType='%s'", DriveQueryEscaper::escapeLiteral($mimeType));
        }

        if ($nameContains !== null && $nameContains !== '') {
            $q .= sprintf(" and name contains '%s'", DriveQueryEscaper::escapeLiteral($nameContains));
        }

        $payload = $this->gateway->listFiles($q, $limit);

        $files = $payload['files'] ?? [];
        if (!is_array($files)) {
            return [];
        }

        $items = [];
        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }
            /** @var array<string, mixed> $file */
            $items[] = $this->mapper->fromFile($file);
            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }
}

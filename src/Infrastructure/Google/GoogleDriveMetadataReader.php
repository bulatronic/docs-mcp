<?php

declare(strict_types=1);

namespace DocsMcp\Infrastructure\Google;

use DocsMcp\Domain\Drive\DriveId;
use DocsMcp\Domain\Drive\DriveItem;
use DocsMcp\Ports\DriveMetadataReader;

final readonly class GoogleDriveMetadataReader implements DriveMetadataReader
{
    public function __construct(
        private GoogleHttpGateway $gateway,
        private DriveItemMapper $mapper = new DriveItemMapper(),
    ) {}

    public function getItem(DriveId $id): DriveItem
    {
        return $this->mapper->fromFile($this->gateway->getFileMetadata($id));
    }

    public function listChildren(DriveId $folderId, int $limit): array
    {
        // Ensure the folder exists (search alone returns [] for unknown parents).
        $this->getItem($folderId);

        // folderId is a validated DriveId ([A-Za-z0-9_-]+), safe inside single quotes.
        $q = sprintf("'%s' in parents and trashed=false", $folderId->value);
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

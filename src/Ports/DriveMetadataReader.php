<?php

declare(strict_types=1);

namespace DocsMcp\Ports;

use DocsMcp\Domain\Drive\DriveId;
use DocsMcp\Domain\Drive\DriveItem;
use DocsMcp\Domain\Error\ItemNotFound;
use DocsMcp\Domain\Error\UpstreamRejected;
use DocsMcp\Domain\Error\UpstreamTemporary;

interface DriveMetadataReader
{
    /**
     * @throws ItemNotFound
     * @throws UpstreamTemporary
     * @throws UpstreamRejected
     */
    public function getItem(DriveId $id): DriveItem;

    /**
     * @param ?string $nameContains Raw name substring; empty/null = no name filter. Adapter escapes for Drive q.
     * @param ?string $mimeType If set, only items of this MIME type are returned.
     *
     * @return list<DriveItem>
     *
     * @throws ItemNotFound
     * @throws UpstreamTemporary
     * @throws UpstreamRejected
     */
    public function listChildren(
        DriveId $folderId,
        int $limit,
        ?string $nameContains = null,
        ?string $mimeType = null,
    ): array;
}

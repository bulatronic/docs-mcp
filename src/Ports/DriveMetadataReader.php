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
     * @return list<DriveItem>
     *
     * @throws ItemNotFound
     * @throws UpstreamTemporary
     * @throws UpstreamRejected
     */
    public function listChildren(DriveId $folderId, int $limit): array;
}

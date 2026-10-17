<?php

declare(strict_types=1);

namespace DocsMcp\Ports;

use DocsMcp\Domain\Drive\Document;
use DocsMcp\Domain\Drive\DriveId;
use DocsMcp\Domain\Error\ItemNotFound;
use DocsMcp\Domain\Error\UpstreamRejected;
use DocsMcp\Domain\Error\UpstreamTemporary;

interface DocumentTextReader
{
    /**
     * @throws ItemNotFound
     * @throws UpstreamTemporary
     * @throws UpstreamRejected
     */
    public function read(DriveId $documentId): Document;
}

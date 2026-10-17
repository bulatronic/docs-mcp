<?php

declare(strict_types=1);

namespace DocsMcp\Ports;

use DocsMcp\Domain\Drive\DriveId;
use DocsMcp\Domain\Error\AppendNotAllowed;
use DocsMcp\Domain\Error\InputTooLarge;
use DocsMcp\Domain\Error\ItemNotFound;
use DocsMcp\Domain\Error\UpstreamRejected;
use DocsMcp\Domain\Error\UpstreamTemporary;
use DocsMcp\Domain\Error\WriteDisabled;

interface DocumentAppender
{
    /**
     * @throws WriteDisabled
     * @throws AppendNotAllowed
     * @throws InputTooLarge
     * @throws ItemNotFound
     * @throws UpstreamTemporary
     * @throws UpstreamRejected
     */
    public function appendText(DriveId $documentId, string $text): void;
}

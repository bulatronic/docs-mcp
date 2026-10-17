<?php

declare(strict_types=1);

namespace DocsMcp\Infrastructure\Google;

use DateTimeImmutable;
use DocsMcp\Domain\Drive\DriveId;
use DocsMcp\Domain\Drive\DriveItem;
use DocsMcp\Domain\Error\UpstreamRejected;

/**
 * Maps Drive files.list / files.get JSON objects to DriveItem.
 */
final class DriveItemMapper
{
    /**
     * @param array<string, mixed> $payload
     */
    public function fromFile(array $payload): DriveItem
    {
        $idRaw = $payload['id'] ?? null;
        if (!is_string($idRaw) || !DriveId::isValid($idRaw)) {
            throw new UpstreamRejected('Drive file payload is missing a valid id');
        }

        $name = $payload['name'] ?? '';
        if (!is_string($name)) {
            $name = '';
        }

        $mimeType = $payload['mimeType'] ?? '';
        if (!is_string($mimeType)) {
            $mimeType = '';
        }

        $parents = [];
        $parentsRaw = $payload['parents'] ?? [];
        if (is_array($parentsRaw)) {
            foreach ($parentsRaw as $parent) {
                if (is_string($parent) && DriveId::isValid($parent)) {
                    $parents[] = new DriveId($parent);
                }
            }
        }

        $trashed = $payload['trashed'] ?? false;
        if (!is_bool($trashed)) {
            $trashed = false;
        }

        $modifiedTime = null;
        $modifiedRaw = $payload['modifiedTime'] ?? null;
        if (is_string($modifiedRaw) && $modifiedRaw !== '') {
            try {
                $modifiedTime = new DateTimeImmutable($modifiedRaw);
            } catch (\Exception) {
                $modifiedTime = null;
            }
        }

        return new DriveItem(
            id: new DriveId($idRaw),
            name: $name,
            mimeType: $mimeType,
            parents: $parents,
            trashed: $trashed,
            modifiedTime: $modifiedTime,
        );
    }
}

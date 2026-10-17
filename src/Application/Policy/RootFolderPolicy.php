<?php

declare(strict_types=1);

namespace DocsMcp\Application\Policy;

use DocsMcp\Domain\Drive\DriveId;
use DocsMcp\Domain\Drive\DriveItem;
use DocsMcp\Domain\Drive\DriveMimeType;
use DocsMcp\Domain\Error\ItemNotFound;
use DocsMcp\Domain\Error\OutsideAllowedFolder;
use DocsMcp\Ports\DriveMetadataReader;

/**
 * Ensures a Drive item lives inside DOCS_ROOT_FOLDER_ID by walking parents.
 */
final class RootFolderPolicy
{
    private const int MAX_DEPTH = 10;

    /** @var array<string, DriveItem> */
    private array $itemCache = [];

    public function __construct(
        private DriveMetadataReader $reader,
        private DriveId $rootFolderId,
    ) {}

    /**
     * @throws ItemNotFound
     * @throws OutsideAllowedFolder
     */
    public function assertInsideRoot(DriveId $id): DriveItem
    {
        $item = $this->getCached($id);

        if ($item->trashed) {
            throw new OutsideAllowedFolder();
        }

        if ($item->id->equals($this->rootFolderId)) {
            return $item;
        }

        /** @var list<array{DriveId, int}> $queue */
        $queue = [];
        foreach ($item->parents as $parentId) {
            $queue[] = [$parentId, 1];
        }

        if ($queue === []) {
            throw new OutsideAllowedFolder();
        }

        $visited = [$item->id->value => true];

        while ($queue !== []) {
            [$parentId, $depth] = array_shift($queue);

            if ($depth > self::MAX_DEPTH) {
                continue;
            }

            if ($parentId->equals($this->rootFolderId)) {
                return $item;
            }

            if (isset($visited[$parentId->value])) {
                // Cycle or diamond: do not walk the same node twice.
                continue;
            }
            $visited[$parentId->value] = true;

            try {
                $parent = $this->getCached($parentId);
            } catch (ItemNotFound) {
                throw new OutsideAllowedFolder();
            }

            if ($parent->trashed) {
                throw new OutsideAllowedFolder();
            }

            if ($parent->id->equals($this->rootFolderId)) {
                return $item;
            }

            if ($parent->parents === []) {
                // Reached the Drive root on this branch; keep scanning other branches.
                continue;
            }

            foreach ($parent->parents as $grandparentId) {
                $queue[] = [$grandparentId, $depth + 1];
            }
        }

        throw new OutsideAllowedFolder();
    }

    /**
     * @throws OutsideAllowedFolder
     */
    public function assertIsDocument(DriveItem $item): void
    {
        if ($item->mimeType !== DriveMimeType::DOCUMENT) {
            throw new OutsideAllowedFolder();
        }
    }

    /**
     * @throws ItemNotFound
     */
    private function getCached(DriveId $id): DriveItem
    {
        if (!isset($this->itemCache[$id->value])) {
            $this->itemCache[$id->value] = $this->reader->getItem($id);
        }

        return $this->itemCache[$id->value];
    }
}

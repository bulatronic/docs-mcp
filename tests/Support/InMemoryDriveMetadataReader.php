<?php

declare(strict_types=1);

namespace DocsMcp\Tests\Support;

use DocsMcp\Domain\Drive\DriveId;
use DocsMcp\Domain\Drive\DriveItem;
use DocsMcp\Domain\Error\ItemNotFound;
use DocsMcp\Ports\DriveMetadataReader;

/**
 * In-memory Drive tree for policy and use-case tests. No Google/HTTP.
 */
final class InMemoryDriveMetadataReader implements DriveMetadataReader
{
    /** @var array<string, DriveItem> */
    private array $items = [];

    /**
     * @param list<DriveItem> $items
     */
    public function __construct(array $items = [])
    {
        foreach ($items as $item) {
            $this->items[$item->id->value] = $item;
        }
    }

    public function add(DriveItem $item): void
    {
        $this->items[$item->id->value] = $item;
    }

    public function getItem(DriveId $id): DriveItem
    {
        if (!isset($this->items[$id->value])) {
            throw new ItemNotFound();
        }

        return $this->items[$id->value];
    }

    public function listChildren(DriveId $folderId, int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        if (!isset($this->items[$folderId->value])) {
            throw new ItemNotFound();
        }

        $children = [];
        foreach ($this->items as $item) {
            foreach ($item->parents as $parentId) {
                if ($parentId->equals($folderId)) {
                    $children[] = $item;
                    break;
                }
            }
            if (count($children) >= $limit) {
                break;
            }
        }

        return $children;
    }
}

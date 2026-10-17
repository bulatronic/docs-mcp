<?php

declare(strict_types=1);

namespace DocsMcp\Application\UseCase;

use DocsMcp\Application\Drive\DriveNameQuery;
use DocsMcp\Application\Policy\RootFolderPolicy;
use DocsMcp\Domain\Drive\DocumentListItem;
use DocsMcp\Domain\Drive\DocumentListResult;
use DocsMcp\Domain\Drive\DriveId;
use DocsMcp\Domain\Drive\DriveItem;
use DocsMcp\Domain\Drive\DriveMimeType;
use DocsMcp\Domain\Error\ItemNotFound;
use DocsMcp\Domain\Error\OutsideAllowedFolder;
use DocsMcp\Ports\DriveMetadataReader;
use InvalidArgumentException;

final class ListDocuments
{
    private const int DEFAULT_LIMIT = 50;

    private const int MAX_LIMIT = 100;

    private const int MAX_FOLDERS = 50;

    private const int CHILDREN_PAGE_SIZE = 1000;

    private const int MAX_PATH_DEPTH = 10;

    public function __construct(
        private DriveMetadataReader $reader,
        private RootFolderPolicy $policy,
        private DriveId $rootFolderId,
    ) {}

    /**
     * @throws InvalidArgumentException
     * @throws ItemNotFound
     * @throws OutsideAllowedFolder
     */
    public function execute(
        ?string $folderId = null,
        ?string $query = null,
        int $limit = self::DEFAULT_LIMIT,
    ): DocumentListResult {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException(sprintf(
                'limit must be between 1 and %d',
                self::MAX_LIMIT,
            ));
        }

        $nameQuery = DriveNameQuery::normalize($query);
        $startId = $this->resolveFolderId($folderId);
        $startItem = $this->policy->assertInsideRoot($startId);
        $this->assertIsFolder($startItem);

        $basePath = $this->pathRelativeToRoot($startItem);

        /** @var list<array{DriveId, string}> $queue */
        $queue = [[$startId, $basePath]];
        $foldersVisited = 0;
        /** @var list<DocumentListItem> $documents */
        $documents = [];

        while ($queue !== [] && count($documents) < $limit && $foldersVisited < self::MAX_FOLDERS) {
            [$currentFolderId, $currentPath] = array_shift($queue);
            ++$foldersVisited;

            $remaining = $limit - count($documents);
            $foundDocs = $this->reader->listChildren(
                $currentFolderId,
                $remaining,
                $nameQuery !== '' ? $nameQuery : null,
                DriveMimeType::DOCUMENT,
            );

            foreach ($foundDocs as $doc) {
                if ($doc->trashed || $doc->mimeType === DriveMimeType::SHORTCUT) {
                    continue;
                }
                $documents[] = new DocumentListItem(
                    id: $doc->id,
                    name: $doc->name,
                    modifiedTime: $doc->modifiedTime,
                    path: $currentPath,
                );
                if (count($documents) >= $limit) {
                    break;
                }
            }

            if ($foldersVisited >= self::MAX_FOLDERS || count($documents) >= $limit) {
                break;
            }

            $subfolders = $this->reader->listChildren(
                $currentFolderId,
                self::CHILDREN_PAGE_SIZE,
                null,
                DriveMimeType::FOLDER,
            );

            foreach ($subfolders as $folder) {
                if ($folder->trashed) {
                    continue;
                }
                if ($foldersVisited + count($queue) >= self::MAX_FOLDERS) {
                    break;
                }
                $queue[] = [$folder->id, $this->joinPath($currentPath, $folder->name)];
            }
        }

        return new DocumentListResult($documents);
    }

    private function resolveFolderId(?string $folderId): DriveId
    {
        if ($folderId === null || trim($folderId) === '') {
            return $this->rootFolderId;
        }

        $trimmed = trim($folderId);
        if (!DriveId::isValid($trimmed)) {
            throw new InvalidArgumentException('folder_id is not a valid Drive id');
        }

        return new DriveId($trimmed);
    }

    /**
     * @throws OutsideAllowedFolder
     */
    private function assertIsFolder(DriveItem $item): void
    {
        if ($item->mimeType !== DriveMimeType::FOLDER) {
            throw new OutsideAllowedFolder();
        }
    }

    private function pathRelativeToRoot(DriveItem $folder): string
    {
        if ($folder->id->equals($this->rootFolderId)) {
            return '';
        }

        $parts = [];
        $current = $folder;

        for ($depth = 0; $depth < self::MAX_PATH_DEPTH; ++$depth) {
            if ($current->id->equals($this->rootFolderId)) {
                break;
            }
            array_unshift($parts, $current->name);
            if ($current->parents === []) {
                break;
            }
            $current = $this->reader->getItem($current->parents[0]);
        }

        return implode('/', $parts);
    }

    private function joinPath(string $base, string $segment): string
    {
        if ($base === '') {
            return $segment;
        }

        return $base . '/' . $segment;
    }
}

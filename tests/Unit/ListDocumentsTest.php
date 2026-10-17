<?php

declare(strict_types=1);

namespace DocsMcp\Tests\Unit;

use DateTimeImmutable;
use DocsMcp\Application\Policy\RootFolderPolicy;
use DocsMcp\Application\UseCase\ListDocuments;
use DocsMcp\Domain\Drive\DriveId;
use DocsMcp\Domain\Drive\DriveItem;
use DocsMcp\Domain\Drive\DriveMimeType;
use DocsMcp\Domain\Error\OutsideAllowedFolder;
use DocsMcp\Domain\Error\PublicErrorMessage;
use DocsMcp\Tests\Support\InMemoryDriveMetadataReader;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ListDocumentsTest extends TestCase
{
    private DriveId $rootId;

    private InMemoryDriveMetadataReader $reader;

    private ListDocuments $useCase;

    protected function setUp(): void
    {
        $this->rootId = new DriveId('rootFolder01');
        $this->reader = new InMemoryDriveMetadataReader([
            $this->folder($this->rootId, 'Root', []),
        ]);
        $this->useCase = new ListDocuments(
            $this->reader,
            new RootFolderPolicy($this->reader, $this->rootId),
            $this->rootId,
        );
    }

    public function testListsDirectDocumentsWithEmptyPath(): void
    {
        $docId = new DriveId('directDoc01');
        $this->reader->add($this->document($docId, 'Alpha', [$this->rootId]));

        $result = $this->useCase->execute();

        self::assertCount(1, $result->documents);
        self::assertSame('directDoc01', $result->documents[0]->id->value);
        self::assertSame('Alpha', $result->documents[0]->name);
        self::assertSame('', $result->documents[0]->path);
    }

    public function testBreadthFirstTraversalBuildsRelativePath(): void
    {
        $reports = new DriveId('folderRep01');
        $year = new DriveId('folder2026a');
        $doc = new DriveId('reportDoc01');

        $this->reader->add($this->folder($reports, 'Отчёты', [$this->rootId]));
        $this->reader->add($this->folder($year, '2026', [$reports]));
        $this->reader->add($this->document($doc, 'Итоги', [$year], new DateTimeImmutable('2026-03-01T12:00:00+00:00')));

        $result = $this->useCase->execute();

        self::assertCount(1, $result->documents);
        self::assertSame('Отчёты/2026', $result->documents[0]->path);
        self::assertSame('2026-03-01T12:00:00+00:00', $result->documents[0]->modifiedTime?->format(DATE_ATOM));
    }

    public function testQueryFiltersByNameSubstring(): void
    {
        $this->reader->add($this->document(new DriveId('docAlpha001'), 'Alpha plan', [$this->rootId]));
        $this->reader->add($this->document(new DriveId('docBeta0001'), 'Beta plan', [$this->rootId]));

        $result = $this->useCase->execute(query: 'alpha');

        self::assertCount(1, $result->documents);
        self::assertSame('docAlpha001', $result->documents[0]->id->value);
    }

    public function testSkipsTrashedAndShortcuts(): void
    {
        $this->reader->add(new DriveItem(
            id: new DriveId('trashDoc001'),
            name: 'Gone',
            mimeType: DriveMimeType::DOCUMENT,
            parents: [$this->rootId],
            trashed: true,
            modifiedTime: null,
        ));
        $this->reader->add(new DriveItem(
            id: new DriveId('shortcut01'),
            name: 'Link',
            mimeType: DriveMimeType::SHORTCUT,
            parents: [$this->rootId],
            trashed: false,
            modifiedTime: null,
        ));
        $this->reader->add($this->document(new DriveId('keepDoc0001'), 'Keep', [$this->rootId]));

        $result = $this->useCase->execute();

        self::assertCount(1, $result->documents);
        self::assertSame('keepDoc0001', $result->documents[0]->id->value);
    }

    public function testRespectsDocumentLimit(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            $this->reader->add($this->document(
                new DriveId(sprintf('limitDoc%03d', $i)),
                "Doc {$i}",
                [$this->rootId],
            ));
        }

        $result = $this->useCase->execute(limit: 2);

        self::assertCount(2, $result->documents);
    }

    public function testRejectsInvalidFolderId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('folder_id is not a valid Drive id');
        $this->useCase->execute(folderId: 'bad');
    }

    public function testRejectsQueryLongerThan100Chars(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('query must be at most 100 characters');
        $this->useCase->execute(query: str_repeat('a', 101));
    }

    public function testRejectsLimitOutOfRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->useCase->execute(limit: 101);
    }

    public function testOutsideRootUsesSharedPublicMessage(): void
    {
        $otherRoot = new DriveId('otherRoot01');
        $this->reader->add($this->folder($otherRoot, 'Other', []));
        $this->reader->add($this->document(new DriveId('outsideDoc1'), 'X', [$otherRoot]));

        try {
            $this->useCase->execute(folderId: 'outsideDoc1');
            self::fail('Expected OutsideAllowedFolder');
        } catch (OutsideAllowedFolder $e) {
            self::assertSame(PublicErrorMessage::NOT_FOUND_OR_OUTSIDE_ROOT, $e->getMessage());
        }
    }

    public function testOptionalFolderIdMustBeInsideRoot(): void
    {
        $reports = new DriveId('folderRep02');
        $doc = new DriveId('nestedDoc01');
        $this->reader->add($this->folder($reports, 'Отчёты', [$this->rootId]));
        $this->reader->add($this->document($doc, 'Nested', [$reports]));

        $result = $this->useCase->execute(folderId: 'folderRep02');

        self::assertCount(1, $result->documents);
        self::assertSame('Отчёты', $result->documents[0]->path);
    }

    /**
     * @param list<DriveId> $parents
     */
    private function folder(DriveId $id, string $name, array $parents): DriveItem
    {
        return new DriveItem(
            id: $id,
            name: $name,
            mimeType: DriveMimeType::FOLDER,
            parents: $parents,
            trashed: false,
            modifiedTime: null,
        );
    }

    /**
     * @param list<DriveId> $parents
     */
    private function document(
        DriveId $id,
        string $name,
        array $parents,
        ?DateTimeImmutable $modifiedTime = null,
    ): DriveItem {
        return new DriveItem(
            id: $id,
            name: $name,
            mimeType: DriveMimeType::DOCUMENT,
            parents: $parents,
            trashed: false,
            modifiedTime: $modifiedTime,
        );
    }
}

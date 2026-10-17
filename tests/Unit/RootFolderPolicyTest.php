<?php

declare(strict_types=1);

namespace DocsMcp\Tests\Unit;

use DocsMcp\Application\Policy\RootFolderPolicy;
use DocsMcp\Domain\Drive\DriveId;
use DocsMcp\Domain\Drive\DriveItem;
use DocsMcp\Domain\Drive\DriveMimeType;
use DocsMcp\Domain\Error\ItemNotFound;
use DocsMcp\Domain\Error\OutsideAllowedFolder;
use DocsMcp\Domain\Error\PublicErrorMessage;
use DocsMcp\Tests\Support\InMemoryDriveMetadataReader;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RootFolderPolicyTest extends TestCase
{
    private DriveId $rootId;

    private InMemoryDriveMetadataReader $reader;

    private RootFolderPolicy $policy;

    protected function setUp(): void
    {
        $this->rootId = new DriveId('rootFolder01');
        $this->reader = new InMemoryDriveMetadataReader([
            $this->folder($this->rootId, 'Root', []),
        ]);
        $this->policy = new RootFolderPolicy($this->reader, $this->rootId);
    }

    public function testDirectChildOfRootIsAllowed(): void
    {
        $docId = new DriveId('directDoc01');
        $doc = $this->document($docId, 'Direct', [$this->rootId]);
        $this->reader->add($doc);

        $result = $this->policy->assertInsideRoot($docId);

        self::assertTrue($result->id->equals($docId));
        $this->policy->assertIsDocument($result);
    }

    public function testDeepDescendantWithinTenLevelsIsAllowed(): void
    {
        $parent = $this->rootId;
        for ($level = 1; $level <= 9; ++$level) {
            $folderId = new DriveId(sprintf('folderLvl%02d', $level));
            $this->reader->add($this->folder($folderId, "L{$level}", [$parent]));
            $parent = $folderId;
        }

        $docId = new DriveId('deepDoc0001');
        $doc = $this->document($docId, 'Deep', [$parent]);
        $this->reader->add($doc);

        $result = $this->policy->assertInsideRoot($docId);

        self::assertTrue($result->id->equals($docId));
    }

    public function testItemOutsideRootIsRejectedWithSharedPublicMessage(): void
    {
        $otherRoot = new DriveId('otherRoot01');
        $this->reader->add($this->folder($otherRoot, 'Other', []));

        $docId = new DriveId('outsideDoc1');
        $this->reader->add($this->document($docId, 'Outside', [$otherRoot]));

        try {
            $this->policy->assertInsideRoot($docId);
            self::fail('Expected OutsideAllowedFolder');
        } catch (OutsideAllowedFolder $e) {
            self::assertSame(PublicErrorMessage::NOT_FOUND_OR_OUTSIDE_ROOT, $e->getMessage());
        }
    }

    public function testMissingItemUsesSamePublicMessageAsOutsideRoot(): void
    {
        try {
            $this->policy->assertInsideRoot(new DriveId('missingDoc1'));
            self::fail('Expected ItemNotFound');
        } catch (ItemNotFound $e) {
            self::assertSame(PublicErrorMessage::NOT_FOUND_OR_OUTSIDE_ROOT, $e->getMessage());
        }
    }

    public function testParentCycleIsRejected(): void
    {
        $a = new DriveId('cycleNodeA1');
        $b = new DriveId('cycleNodeB1');
        $this->reader->add($this->folder($a, 'A', [$b]));
        $this->reader->add($this->folder($b, 'B', [$a]));

        $docId = new DriveId('cycleDoc001');
        $this->reader->add($this->document($docId, 'Cyclic', [$a]));

        $this->expectException(OutsideAllowedFolder::class);
        $this->policy->assertInsideRoot($docId);
    }

    public function testDepthElevenIsRejected(): void
    {
        $parent = $this->rootId;
        for ($level = 1; $level <= 11; ++$level) {
            $folderId = new DriveId(sprintf('deepFolder%02d', $level));
            $this->reader->add($this->folder($folderId, "D{$level}", [$parent]));
            $parent = $folderId;
        }

        $docId = new DriveId('tooDeepDoc1');
        $this->reader->add($this->document($docId, 'Too deep', [$parent]));

        $this->expectException(OutsideAllowedFolder::class);
        $this->policy->assertInsideRoot($docId);
    }

    public function testTrashedItemIsRejected(): void
    {
        $docId = new DriveId('trashDoc001');
        $this->reader->add(new DriveItem(
            id: $docId,
            name: 'Trashed',
            mimeType: DriveMimeType::DOCUMENT,
            parents: [$this->rootId],
            trashed: true,
            modifiedTime: null,
        ));

        $this->expectException(OutsideAllowedFolder::class);
        $this->policy->assertInsideRoot($docId);
    }

    public function testShortcutIsRejectedByAssertIsDocument(): void
    {
        $shortcutId = new DriveId('shortcut01');
        $shortcut = new DriveItem(
            id: $shortcutId,
            name: 'Shortcut',
            mimeType: DriveMimeType::SHORTCUT,
            parents: [$this->rootId],
            trashed: false,
            modifiedTime: null,
        );
        $this->reader->add($shortcut);

        $item = $this->policy->assertInsideRoot($shortcutId);

        $this->expectException(OutsideAllowedFolder::class);
        $this->policy->assertIsDocument($item);
    }

    public function testInvalidDriveIdThrowsInvalidArgument(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Drive id');

        new DriveId('short');
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
    private function document(DriveId $id, string $name, array $parents): DriveItem
    {
        return new DriveItem(
            id: $id,
            name: $name,
            mimeType: DriveMimeType::DOCUMENT,
            parents: $parents,
            trashed: false,
            modifiedTime: null,
        );
    }
}

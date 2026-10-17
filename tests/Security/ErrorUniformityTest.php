<?php

declare(strict_types=1);

namespace DocsMcp\Tests\Security;

use DocsMcp\Application\Policy\RootFolderPolicy;
use DocsMcp\Application\UseCase\GetDocumentContent;
use DocsMcp\Domain\Drive\DriveId;
use DocsMcp\Domain\Drive\DriveItem;
use DocsMcp\Domain\Drive\DriveMimeType;
use DocsMcp\Domain\Error\ItemNotFound;
use DocsMcp\Domain\Error\OutsideAllowedFolder;
use DocsMcp\Domain\Error\PublicErrorMessage;
use DocsMcp\Tests\Support\InMemoryDocumentTextReader;
use DocsMcp\Tests\Support\InMemoryDriveMetadataReader;
use PHPUnit\Framework\TestCase;

final class ErrorUniformityTest extends TestCase
{
    public function testItemNotFoundAndOutsideRootShareIdenticalPublicMessage(): void
    {
        self::assertSame(
            PublicErrorMessage::NOT_FOUND_OR_OUTSIDE_ROOT,
            (new ItemNotFound())->getMessage(),
        );
        self::assertSame(
            PublicErrorMessage::NOT_FOUND_OR_OUTSIDE_ROOT,
            (new OutsideAllowedFolder())->getMessage(),
        );
        self::assertSame(
            (new ItemNotFound())->getMessage(),
            (new OutsideAllowedFolder())->getMessage(),
        );
    }

    public function testGetDocumentContentMapsBothCasesToSameMessage(): void
    {
        $rootId = new DriveId('rootFolder01');
        $otherRoot = new DriveId('otherRoot01');
        $outsideDoc = new DriveId('outsideDoc1');

        $reader = new InMemoryDriveMetadataReader([
            new DriveItem($rootId, 'Root', DriveMimeType::FOLDER, [], false, null),
            new DriveItem($otherRoot, 'Other', DriveMimeType::FOLDER, [], false, null),
            new DriveItem($outsideDoc, 'Out', DriveMimeType::DOCUMENT, [$otherRoot], false, null),
        ]);
        $useCase = new GetDocumentContent(
            new InMemoryDocumentTextReader(),
            new RootFolderPolicy($reader, $rootId),
            1000,
        );

        $missingMessage = null;
        try {
            $useCase->execute('missingDoc1');
        } catch (ItemNotFound $e) {
            $missingMessage = $e->getMessage();
        }
        self::assertNotNull($missingMessage);

        $outsideMessage = null;
        try {
            $useCase->execute('outsideDoc1');
        } catch (OutsideAllowedFolder $e) {
            $outsideMessage = $e->getMessage();
        }
        self::assertNotNull($outsideMessage);
        self::assertSame($missingMessage, $outsideMessage);
        self::assertSame(PublicErrorMessage::NOT_FOUND_OR_OUTSIDE_ROOT, $missingMessage);
    }
}

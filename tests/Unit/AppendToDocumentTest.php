<?php

declare(strict_types=1);

namespace DocsMcp\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use DocsMcp\Application\Policy\RootFolderPolicy;
use DocsMcp\Application\UseCase\AppendToDocument;
use DocsMcp\Domain\Drive\DriveId;
use DocsMcp\Domain\Drive\DriveItem;
use DocsMcp\Domain\Drive\DriveMimeType;
use DocsMcp\Domain\Error\AppendNotAllowed;
use DocsMcp\Domain\Error\InputTooLarge;
use DocsMcp\Domain\Error\OutsideAllowedFolder;
use DocsMcp\Domain\Error\PublicErrorMessage;
use DocsMcp\Domain\Error\UpstreamTemporary;
use DocsMcp\Domain\Error\WriteDisabled;
use DocsMcp\Tests\Support\FixedClock;
use DocsMcp\Tests\Support\InMemoryDocumentAppender;
use DocsMcp\Tests\Support\InMemoryDriveMetadataReader;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AppendToDocumentTest extends TestCase
{
    private DriveId $rootId;

    private DriveId $docId;

    private InMemoryDriveMetadataReader $metadata;

    private InMemoryDocumentAppender $appender;

    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->rootId = new DriveId('rootFolder01');
        $this->docId = new DriveId('allowedDoc1');
        $this->metadata = new InMemoryDriveMetadataReader([
            new DriveItem($this->rootId, 'Root', DriveMimeType::FOLDER, [], false, null),
            new DriveItem(
                $this->docId,
                'Doc',
                DriveMimeType::DOCUMENT,
                [$this->rootId],
                false,
                new DateTimeImmutable('2026-10-17T10:00:00+00:00'),
            ),
        ]);
        $this->appender = new InMemoryDocumentAppender();
        $this->clock = new FixedClock(new DateTimeImmutable('2026-10-17 15:30:00', new DateTimeZone('Europe/Moscow')));
    }

    public function testWriteDisabledMakesNoNetworkCalls(): void
    {
        $useCase = $this->useCase(writeEnabled: false, allowedIds: ['allowedDoc1']);

        try {
            $useCase->execute('allowedDoc1', 'hello');
            self::fail('Expected WriteDisabled');
        } catch (WriteDisabled) {
            self::assertSame([], $this->appender->calls);
        }
    }

    public function testDocumentNotInAllowlistIsRejectedWithoutAppend(): void
    {
        $useCase = $this->useCase(writeEnabled: true, allowedIds: ['otherDoc001']);

        try {
            $useCase->execute('allowedDoc1', 'hello');
            self::fail('Expected AppendNotAllowed');
        } catch (AppendNotAllowed) {
            self::assertSame([], $this->appender->calls);
        }
    }

    public function testOutsideRootIsRejectedWithSharedMessage(): void
    {
        $outside = new DriveId('outsideDoc1');
        $otherRoot = new DriveId('otherRoot01');
        $this->metadata->add(new DriveItem($otherRoot, 'Other', DriveMimeType::FOLDER, [], false, null));
        $this->metadata->add(new DriveItem($outside, 'Out', DriveMimeType::DOCUMENT, [$otherRoot], false, null));

        $useCase = $this->useCase(writeEnabled: true, allowedIds: ['outsideDoc1']);

        try {
            $useCase->execute('outsideDoc1', 'hello');
            self::fail('Expected OutsideAllowedFolder');
        } catch (OutsideAllowedFolder $e) {
            self::assertSame(PublicErrorMessage::NOT_FOUND_OR_OUTSIDE_ROOT, $e->getMessage());
            self::assertSame([], $this->appender->calls);
        }
    }

    public function testTextLimitsAndEmptyAfterNormalization(): void
    {
        $useCase = $this->useCase(writeEnabled: true, allowedIds: ['allowedDoc1'], maxAppendChars: 5);

        try {
            $useCase->execute('allowedDoc1', '');
            self::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('text must not be empty', $e->getMessage());
        }

        try {
            $useCase->execute('allowedDoc1', "\x00\x01");
            self::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('text must not be empty', $e->getMessage());
        }

        $this->expectException(InputTooLarge::class);
        $useCase->execute('allowedDoc1', 'abcdef');
    }

    public function testHeadingLongerThan200IsRejected(): void
    {
        $useCase = $this->useCase(writeEnabled: true, allowedIds: ['allowedDoc1']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('heading must be at most 200 characters');
        $useCase->execute('allowedDoc1', 'ok', str_repeat('h', 201));
    }

    public function testNormalizesNewlinesAndStripsControlCharacters(): void
    {
        $useCase = $this->useCase(writeEnabled: true, allowedIds: ['allowedDoc1']);

        $useCase->execute('allowedDoc1', "a\r\nb\rc\x00d\te");

        self::assertCount(1, $this->appender->calls);
        self::assertSame("\na\nb\ncd\te\n", $this->appender->calls[0][1]);
    }

    public function testBlockFormatWithFixedClockAndHeading(): void
    {
        $useCase = $this->useCase(writeEnabled: true, allowedIds: ['allowedDoc1']);

        $result = $useCase->execute('allowedDoc1', "line1\nline2", 'Итог');

        self::assertSame("\n2026-10-17 15:30 — Итог\nline1\nline2\n", $this->appender->calls[0][1]);
        self::assertSame(mb_strlen("\n2026-10-17 15:30 — Итог\nline1\nline2\n"), $result->appendedChars);
        self::assertSame('allowedDoc1', $result->documentId->value);
        self::assertSame('2026-10-17T10:00:00+00:00', $result->modifiedTime?->format(DATE_ATOM));
    }

    public function testBlockFormatWithoutHeading(): void
    {
        $useCase = $this->useCase(writeEnabled: true, allowedIds: ['allowedDoc1']);

        $useCase->execute('allowedDoc1', 'only text');

        self::assertSame("\nonly text\n", $this->appender->calls[0][1]);
    }

    public function testRepeatedCallAppendsAgain(): void
    {
        $useCase = $this->useCase(writeEnabled: true, allowedIds: ['allowedDoc1']);

        $useCase->execute('allowedDoc1', 'one');
        $useCase->execute('allowedDoc1', 'two');

        self::assertCount(2, $this->appender->calls);
        self::assertSame("\none\n", $this->appender->calls[0][1]);
        self::assertSame("\ntwo\n", $this->appender->calls[1][1]);
    }

    public function testUpstreamErrorPropagatesAfterPolicyPassed(): void
    {
        $this->appender->nextError = new UpstreamTemporary();
        $useCase = $this->useCase(writeEnabled: true, allowedIds: ['allowedDoc1']);

        $this->expectException(UpstreamTemporary::class);
        $useCase->execute('allowedDoc1', 'hello');
    }

    /**
     * @param list<string> $allowedIds
     */
    private function useCase(
        bool $writeEnabled,
        array $allowedIds,
        int $maxAppendChars = 5000,
    ): AppendToDocument {
        return new AppendToDocument(
            $this->appender,
            $this->metadata,
            new RootFolderPolicy($this->metadata, $this->rootId),
            $this->clock,
            $writeEnabled,
            $allowedIds,
            $maxAppendChars,
        );
    }
}

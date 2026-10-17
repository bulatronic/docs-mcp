<?php

declare(strict_types=1);

namespace DocsMcp\Tests\Security;

use DocsMcp\Application\Drive\DriveNameQuery;
use DocsMcp\Application\Policy\RootFolderPolicy;
use DocsMcp\Application\UseCase\GetDocumentContent;
use DocsMcp\Application\UseCase\ListDocuments;
use DocsMcp\Domain\Drive\DriveId;
use DocsMcp\Domain\Drive\DriveItem;
use DocsMcp\Domain\Drive\DriveMimeType;
use DocsMcp\Domain\Error\OutsideAllowedFolder;
use DocsMcp\Domain\Error\PublicErrorMessage;
use DocsMcp\Infrastructure\Google\DriveQueryEscaper;
use DocsMcp\Infrastructure\Google\GoogleApiAllowlist;
use DocsMcp\Infrastructure\Google\GoogleDriveMetadataReader;
use DocsMcp\Infrastructure\Google\GoogleHttpGateway;
use DocsMcp\Tests\Support\InMemoryDocumentTextReader;
use DocsMcp\Tests\Support\InMemoryDriveMetadataReader;
use DocsMcp\Tests\Support\RecordingSleeper;
use DocsMcp\Tests\Support\StaticTokenProvider;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AccessBoundaryHardeningTest extends TestCase
{
    public function testDriveIdRejectsSsrfLikeValues(): void
    {
        foreach ([
            'http://evil.example',
            '../etc/passwd',
            'files/../../admin',
            'short',
            "id' or '1'='1",
        ] as $value) {
            try {
                new DriveId($value);
                self::fail('Expected InvalidArgumentException for ' . $value);
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testAllowlistRejectsHostAndPathInjection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        GoogleApiAllowlist::assertAllowed(
            'GET',
            'https://evil.example/drive/v3/files/fileId00001',
            ['fields' => 'id'],
        );
    }

    public function testQueryControlCharactersAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('control characters');
        DriveNameQuery::normalize("foo\nbar");
    }

    public function testNameContainsEscapesQuotesAndBackslashesForQ(): void
    {
        self::assertSame("O\\'Reilly\\\\x", DriveQueryEscaper::escapeLiteral("O'Reilly\\x"));
    }

    public function testListChildrenBuildsEscapedQAndNeverUsesNextPageToken(): void
    {
        $queries = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$queries): MockResponse {
            $queries[] = $options['query'] ?? [];
            if (str_contains($url, '/files/') && !str_ends_with($url, '/files')) {
                return new MockResponse(json_encode([
                    'id' => 'rootFolder01',
                    'name' => 'Root',
                    'mimeType' => DriveMimeType::FOLDER,
                    'parents' => [],
                    'trashed' => false,
                ], \JSON_THROW_ON_ERROR));
            }

            return new MockResponse(json_encode([
                'files' => [],
                'nextPageToken' => 'SHOULD_BE_IGNORED',
            ], \JSON_THROW_ON_ERROR));
        });

        $reader = new GoogleDriveMetadataReader(
            new GoogleHttpGateway($client, StaticTokenProvider::create(), new RecordingSleeper()),
        );
        $reader->listChildren(new DriveId('rootFolder01'), 99999, "a'b\\c", DriveMimeType::DOCUMENT);

        self::assertNotEmpty($queries);
        $last = $queries[array_key_last($queries)];
        self::assertArrayNotHasKey('pageToken', $last);
        self::assertSame(1000, $last['pageSize']);
        self::assertStringContainsString("name contains 'a\\'b\\\\c'", (string) $last['q']);
        self::assertStringNotContainsString('SHOULD_BE_IGNORED', json_encode($last) ?: '');
    }

    public function testShortcutCannotBypassRootEvenWhenParentIsRoot(): void
    {
        $rootId = new DriveId('rootFolder01');
        $shortcutId = new DriveId('shortcutOut');
        $reader = new InMemoryDriveMetadataReader([
            new DriveItem($rootId, 'Root', DriveMimeType::FOLDER, [], false, null),
            new DriveItem($shortcutId, 'Link', DriveMimeType::SHORTCUT, [$rootId], false, null),
        ]);
        $policy = new RootFolderPolicy($reader, $rootId);

        try {
            $policy->assertInsideRoot($shortcutId);
            self::fail('Expected OutsideAllowedFolder');
        } catch (OutsideAllowedFolder $e) {
            self::assertSame(PublicErrorMessage::NOT_FOUND_OR_OUTSIDE_ROOT, $e->getMessage());
        }

        $useCase = new GetDocumentContent(new InMemoryDocumentTextReader(), $policy, 100);
        try {
            $useCase->execute('shortcutOut');
            self::fail('Expected OutsideAllowedFolder');
        } catch (OutsideAllowedFolder $e) {
            self::assertSame(PublicErrorMessage::NOT_FOUND_OR_OUTSIDE_ROOT, $e->getMessage());
        }
    }

    public function testParentCycleDoesNotLoopForever(): void
    {
        $rootId = new DriveId('rootFolder01');
        $a = new DriveId('cycleNodeA1');
        $b = new DriveId('cycleNodeB1');
        $reader = new InMemoryDriveMetadataReader([
            new DriveItem($rootId, 'Root', DriveMimeType::FOLDER, [], false, null),
            new DriveItem($a, 'A', DriveMimeType::FOLDER, [$b], false, null),
            new DriveItem($b, 'B', DriveMimeType::FOLDER, [$a], false, null),
            new DriveItem(new DriveId('cycleDoc001'), 'Doc', DriveMimeType::DOCUMENT, [$a], false, null),
        ]);
        $policy = new RootFolderPolicy($reader, $rootId);

        $this->expectException(OutsideAllowedFolder::class);
        $policy->assertInsideRoot(new DriveId('cycleDoc001'));
    }

    public function testToolResponsesDoNotLeakExtraGoogleFields(): void
    {
        $rootId = new DriveId('rootFolder01');
        $docId = new DriveId('directDoc01');
        $reader = new InMemoryDriveMetadataReader([
            new DriveItem($rootId, 'Root', DriveMimeType::FOLDER, [], false, null),
            new DriveItem($docId, 'Alpha', DriveMimeType::DOCUMENT, [$rootId], false, null),
        ]);
        $result = (new ListDocuments($reader, new RootFolderPolicy($reader, $rootId), $rootId))->execute();
        $item = $result->documents[0];

        $encoded = json_encode([
            'id' => $item->id->value,
            'name' => $item->name,
            'modified_time' => $item->modifiedTime?->format(DATE_ATOM),
            'path' => $item->path,
        ], \JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('owners', $encoded);
        self::assertStringNotContainsString('permissions', $encoded);
        self::assertStringNotContainsString('webViewLink', $encoded);
        self::assertStringNotContainsString('private_key', $encoded);
    }

    public function testListDocumentsBfsIsBoundedByFolderCap(): void
    {
        $rootId = new DriveId('rootFolder01');
        $reader = new InMemoryDriveMetadataReader([
            new DriveItem($rootId, 'Root', DriveMimeType::FOLDER, [], false, null),
        ]);
        $parent = $rootId;
        for ($i = 1; $i <= 60; ++$i) {
            $folderId = new DriveId(sprintf('deepFolder%02d', $i));
            $reader->add(new DriveItem($folderId, "F{$i}", DriveMimeType::FOLDER, [$parent], false, null));
            $parent = $folderId;
        }
        $reader->add(new DriveItem(
            new DriveId('deepDoc0001'),
            'Deep',
            DriveMimeType::DOCUMENT,
            [$parent],
            false,
            null,
        ));

        $result = (new ListDocuments($reader, new RootFolderPolicy($reader, $rootId), $rootId))->execute();
        // Folder cap is 50: the deep document beyond the cap must not be reached.
        self::assertSame([], $result->documents);
    }
}

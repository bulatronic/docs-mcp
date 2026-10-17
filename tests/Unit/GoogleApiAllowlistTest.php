<?php

declare(strict_types=1);

namespace DocsMcp\Tests\Unit;

use DocsMcp\Infrastructure\Google\GoogleApiAllowlist;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GoogleApiAllowlistTest extends TestCase
{
    public function testAllowsDriveSearchWithQAndFields(): void
    {
        GoogleApiAllowlist::assertAllowed(
            'GET',
            GoogleApiAllowlist::DRIVE_FILES,
            [
                'q' => "'rootFolder01' in parents and trashed=false",
                'fields' => 'files(id,name,mimeType,parents,trashed,modifiedTime)',
            ],
        );
        $this->addToAssertionCount(1);
    }

    public function testAllowsDriveFileGetWithAllowlistedFields(): void
    {
        GoogleApiAllowlist::assertAllowed(
            'GET',
            GoogleApiAllowlist::DRIVE_FILES . '/fileId00001',
            ['fields' => 'id,name,mimeType,parents,trashed,modifiedTime'],
        );
        $this->addToAssertionCount(1);
    }

    public function testAllowsDocsGetAndBatchUpdate(): void
    {
        GoogleApiAllowlist::assertAllowed('GET', GoogleApiAllowlist::DOCS_DOCUMENTS . '/docId000001');
        GoogleApiAllowlist::assertAllowed('POST', GoogleApiAllowlist::DOCS_DOCUMENTS . '/docId000001:batchUpdate');
        $this->addToAssertionCount(1);
    }

    #[DataProvider('forbiddenRequests')]
    public function testRejectsForbiddenMethodOrUrl(string $method, string $url, array $query = []): void
    {
        $this->expectException(InvalidArgumentException::class);
        GoogleApiAllowlist::assertAllowed($method, $url, $query);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2?: array<string, scalar|null>}>
     */
    public static function forbiddenRequests(): iterable
    {
        $file = GoogleApiAllowlist::DRIVE_FILES . '/fileId00001';
        $doc = GoogleApiAllowlist::DOCS_DOCUMENTS . '/docId000001';

        yield 'DELETE file' => ['DELETE', $file, ['fields' => 'id']];
        yield 'PATCH file' => ['PATCH', $file, ['fields' => 'id']];
        yield 'PUT file' => ['PUT', $file];
        yield 'DELETE document' => ['DELETE', $doc];
        yield 'foreign host' => ['GET', 'https://example.com/drive/v3/files', ['q' => 'x', 'fields' => 'id']];
        yield 'drive permissions' => ['GET', GoogleApiAllowlist::DRIVE_FILES . '/fileId00001/permissions'];
        yield 'search without q' => ['GET', GoogleApiAllowlist::DRIVE_FILES, ['fields' => 'files(id)']];
        yield 'get with forbidden field' => ['GET', $file, ['fields' => 'id,permissions']];
        yield 'create files POST' => ['POST', GoogleApiAllowlist::DRIVE_FILES];
    }
}

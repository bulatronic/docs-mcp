<?php

declare(strict_types=1);

namespace DocsMcp\Tests\Unit;

use DocsMcp\Domain\Drive\DriveId;
use DocsMcp\Infrastructure\Google\GoogleDriveMetadataReader;
use DocsMcp\Infrastructure\Google\GoogleHttpGateway;
use DocsMcp\Tests\Support\RecordingSleeper;
use DocsMcp\Tests\Support\StaticTokenProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GoogleDriveMetadataReaderTest extends TestCase
{
    public function testListChildrenUsesParentsQueryAndSkipsNetworkOnMissingFolder(): void
    {
        $urls = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$urls): MockResponse {
            $urls[] = $url;

            return new MockResponse('{"error":{"message":"notFound"}}', ['http_code' => 404]);
        });
        $reader = new GoogleDriveMetadataReader(
            new GoogleHttpGateway($client, StaticTokenProvider::create(), new RecordingSleeper()),
        );

        try {
            $reader->listChildren(new DriveId('missingFold'), 10);
            self::fail('Expected ItemNotFound');
        } catch (\DocsMcp\Domain\Error\ItemNotFound) {
            self::assertCount(1, $urls);
            self::assertStringContainsString('/files/missingFold', $urls[0]);
        }
    }

    public function testListChildrenMapsFiles(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'id' => 'rootFolder01',
                'name' => 'Root',
                'mimeType' => 'application/vnd.google-apps.folder',
                'parents' => [],
                'trashed' => false,
            ], \JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode([
                'files' => [
                    [
                        'id' => 'childDoc001',
                        'name' => 'Child',
                        'mimeType' => 'application/vnd.google-apps.document',
                        'parents' => ['rootFolder01'],
                        'trashed' => false,
                        'modifiedTime' => '2026-10-17T10:00:00Z',
                    ],
                ],
            ], \JSON_THROW_ON_ERROR)),
        ]);

        $reader = new GoogleDriveMetadataReader(
            new GoogleHttpGateway($client, StaticTokenProvider::create(), new RecordingSleeper()),
        );

        $items = $reader->listChildren(new DriveId('rootFolder01'), 5);

        self::assertCount(1, $items);
        self::assertSame('childDoc001', $items[0]->id->value);
        self::assertSame('Child', $items[0]->name);
    }
}

<?php

declare(strict_types=1);

namespace DocsMcp\Tests\Unit;

use DocsMcp\Domain\Drive\DriveId;
use DocsMcp\Domain\Drive\DriveMimeType;
use DocsMcp\Infrastructure\Google\DriveQueryEscaper;
use DocsMcp\Infrastructure\Google\GoogleDriveMetadataReader;
use DocsMcp\Infrastructure\Google\GoogleHttpGateway;
use DocsMcp\Tests\Support\RecordingSleeper;
use DocsMcp\Tests\Support\StaticTokenProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DriveQueryEscaperTest extends TestCase
{
    public function testEscapesBackslashAndSingleQuote(): void
    {
        self::assertSame("a\\\\b\\'c", DriveQueryEscaper::escapeLiteral("a\\b'c"));
    }

    public function testListChildrenBuildsSafeNameContainsQuery(): void
    {
        $queries = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$queries): MockResponse {
            $queries[] = $options['query']['q'] ?? null;

            if (str_contains($url, '/files/') && !str_contains($url, 'files?')) {
                return new MockResponse(json_encode([
                    'id' => 'rootFolder01',
                    'name' => 'Root',
                    'mimeType' => DriveMimeType::FOLDER,
                    'parents' => [],
                    'trashed' => false,
                ], \JSON_THROW_ON_ERROR));
            }

            return new MockResponse(json_encode(['files' => []], \JSON_THROW_ON_ERROR));
        });

        $reader = new GoogleDriveMetadataReader(
            new GoogleHttpGateway($client, StaticTokenProvider::create(), new RecordingSleeper()),
        );
        $reader->listChildren(
            new DriveId('rootFolder01'),
            10,
            "O'Reilly\\Docs",
            DriveMimeType::DOCUMENT,
        );

        self::assertNotEmpty($queries);
        $q = (string) end($queries);
        self::assertStringContainsString("name contains 'O\\'Reilly\\\\Docs'", $q);
        self::assertStringContainsString("mimeType='application/vnd.google-apps.document'", $q);
        self::assertStringContainsString("'rootFolder01' in parents and trashed=false", $q);
    }
}

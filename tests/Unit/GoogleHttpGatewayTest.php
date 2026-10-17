<?php

declare(strict_types=1);

namespace DocsMcp\Tests\Unit;

use DocsMcp\Domain\Drive\DriveId;
use DocsMcp\Domain\Error\ItemNotFound;
use DocsMcp\Domain\Error\UpstreamRejected;
use DocsMcp\Domain\Error\UpstreamTemporary;
use DocsMcp\Infrastructure\Google\GoogleApiAllowlist;
use DocsMcp\Infrastructure\Google\GoogleHttpGateway;
use DocsMcp\Tests\Support\RecordingSleeper;
use DocsMcp\Tests\Support\StaticTokenProvider;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GoogleHttpGatewayTest extends TestCase
{
    public function testInsertTextAtEndSendsSingleAllowlistedBatchUpdate(): void
    {
        $requests = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse('{}', ['http_code' => 200]);
        });

        $gateway = new GoogleHttpGateway($client, StaticTokenProvider::create('tok_secret_abc'), new RecordingSleeper());
        $gateway->insertTextAtEnd(new DriveId('docId000001'), 'smoke test');

        self::assertCount(1, $requests);
        self::assertSame('POST', $requests[0]['method']);
        self::assertSame(GoogleApiAllowlist::DOCS_DOCUMENTS . '/docId000001:batchUpdate', $requests[0]['url']);

        $options = $requests[0]['options'];
        $jsonBody = $options['json'] ?? null;
        if ($jsonBody === null && isset($options['body']) && is_string($options['body'])) {
            $jsonBody = json_decode($options['body'], true, 512, \JSON_THROW_ON_ERROR);
        }
        self::assertSame([
            'requests' => [
                [
                    'insertText' => [
                        'text' => 'smoke test',
                        'endOfSegmentLocation' => [
                            'segmentId' => '',
                        ],
                    ],
                ],
            ],
        ], $jsonBody);

        $normalized = $options['normalized_headers']['authorization'][0] ?? '';
        self::assertSame('Authorization: Bearer tok_secret_abc', $normalized);
    }

    public function testMaps404And403ToItemNotFound(): void
    {
        $client = new MockHttpClient([
            new MockResponse('{"error":{"message":"not found"}}', ['http_code' => 404]),
            new MockResponse('{"error":{"message":"forbidden"}}', ['http_code' => 403]),
        ]);
        $gateway = new GoogleHttpGateway($client, StaticTokenProvider::create(), new RecordingSleeper());

        try {
            $gateway->getFileMetadata(new DriveId('missing0001'));
            self::fail('Expected ItemNotFound');
        } catch (ItemNotFound $e) {
            self::assertSame('Документ не найден или вне разрешённой папки', $e->getMessage());
        }

        $this->expectException(ItemNotFound::class);
        $gateway->getDocument(new DriveId('forbidden01'));
    }

    public function testMapsStorageQuotaExceededToUpstreamRejected(): void
    {
        $body = json_encode([
            'error' => [
                'errors' => [
                    ['reason' => 'storageQuotaExceeded', 'message' => 'quota'],
                ],
                'message' => 'The user\'s Drive storage quota has been exceeded.',
            ],
        ], \JSON_THROW_ON_ERROR);

        $client = new MockHttpClient(new MockResponse($body, ['http_code' => 403]));
        $gateway = new GoogleHttpGateway($client, StaticTokenProvider::create(), new RecordingSleeper());

        try {
            $gateway->getFileMetadata(new DriveId('quotaFile01'));
            self::fail('Expected UpstreamRejected');
        } catch (UpstreamRejected $e) {
            self::assertSame('Google Drive storage quota exceeded', $e->getMessage());
        }
    }

    public function testRetriesRetryableStatusesThenSucceeds(): void
    {
        $sleeper = new RecordingSleeper();
        $client = new MockHttpClient([
            new MockResponse('busy', ['http_code' => 429]),
            new MockResponse('error', ['http_code' => 503]),
            new MockResponse('{"id":"fileId00001","name":"Doc","mimeType":"application/vnd.google-apps.document","parents":["rootFolder01"],"trashed":false}', ['http_code' => 200]),
        ]);
        $gateway = new GoogleHttpGateway($client, StaticTokenProvider::create(), $sleeper);

        $payload = $gateway->getFileMetadata(new DriveId('fileId00001'));

        self::assertSame('fileId00001', $payload['id']);
        self::assertSame([1.0, 2.0], $sleeper->sleeps);
    }

    public function testGivesUpAfterRetriesOn5xx(): void
    {
        $sleeper = new RecordingSleeper();
        $client = new MockHttpClient([
            new MockResponse('e1', ['http_code' => 500]),
            new MockResponse('e2', ['http_code' => 502]),
            new MockResponse('e3', ['http_code' => 503]),
            new MockResponse('e4', ['http_code' => 504]),
        ]);
        $gateway = new GoogleHttpGateway($client, StaticTokenProvider::create(), $sleeper);

        $this->expectException(UpstreamTemporary::class);
        try {
            $gateway->getDocument(new DriveId('tempFail001'));
        } finally {
            self::assertSame([1.0, 2.0, 4.0], $sleeper->sleeps);
        }
    }

    public function testDoesNotRetryOrdinary4xx(): void
    {
        $sleeper = new RecordingSleeper();
        $client = new MockHttpClient([
            new MockResponse('{"error":{"message":"bad request SECRET_TOKEN"}}', ['http_code' => 400]),
        ]);
        $gateway = new GoogleHttpGateway($client, StaticTokenProvider::create('SECRET_TOKEN'), $sleeper);

        try {
            $gateway->getDocument(new DriveId('badRequest1'));
            self::fail('Expected UpstreamRejected');
        } catch (UpstreamRejected $e) {
            self::assertSame('Upstream service rejected the request', $e->getMessage());
            self::assertStringNotContainsString('SECRET_TOKEN', $e->getMessage());
        }
        self::assertSame([], $sleeper->sleeps);
    }

    public function testExceptionMessagesNeverContainTokenOrKeyMaterial(): void
    {
        $secret = 'ya29.SECRET-token-value';
        $keySnippet = 'BEGIN PRIVATE KEY';
        $client = new MockHttpClient(new MockResponse(
            '{"error":{"message":"denied ' . $secret . ' ' . $keySnippet . '"}}',
            ['http_code' => 403],
        ));
        $gateway = new GoogleHttpGateway($client, StaticTokenProvider::create($secret), new RecordingSleeper());

        try {
            $gateway->getFileMetadata(new DriveId('secretFile1'));
            self::fail('Expected ItemNotFound');
        } catch (ItemNotFound $e) {
            self::assertStringNotContainsString($secret, $e->getMessage());
            self::assertStringNotContainsString($keySnippet, $e->getMessage());
            self::assertStringNotContainsString('Bearer', $e->getMessage());
        }
    }

    public function testAllowlistRejectionHappensWithoutHttpCall(): void
    {
        $called = 0;
        $client = new MockHttpClient(function () use (&$called): MockResponse {
            ++$called;

            return new MockResponse('{}');
        });

        $this->expectException(InvalidArgumentException::class);
        try {
            GoogleApiAllowlist::assertAllowed('DELETE', GoogleApiAllowlist::DRIVE_FILES . '/fileId00001');
        } finally {
            self::assertSame(0, $called);
            // Keep gateway constructed so the mock client is wired the same way as production.
            new GoogleHttpGateway($client, StaticTokenProvider::create(), new RecordingSleeper());
        }
    }
}

<?php

declare(strict_types=1);

namespace DocsMcp\Tests\Unit;

use DocsMcp\Domain\Drive\DriveId;
use DocsMcp\Domain\Error\AppendNotAllowed;
use DocsMcp\Domain\Error\InputTooLarge;
use DocsMcp\Domain\Error\WriteDisabled;
use DocsMcp\Infrastructure\Config\DocsConfig;
use DocsMcp\Infrastructure\Google\GoogleDocumentAppender;
use DocsMcp\Infrastructure\Google\GoogleHttpGateway;
use DocsMcp\Tests\Support\RecordingSleeper;
use DocsMcp\Tests\Support\StaticTokenProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GoogleDocumentAppenderTest extends TestCase
{
    public function testAppendsWhenWriteEnabledAndIdAllowlisted(): void
    {
        $requests = 0;
        $client = new MockHttpClient(function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('{}');
        });
        $gateway = new GoogleHttpGateway($client, StaticTokenProvider::create(), new RecordingSleeper());
        $appender = new GoogleDocumentAppender($gateway, $this->config(
            writeEnabled: true,
            appendAllowedIds: ['docId000001'],
        ));

        $appender->appendText(new DriveId('docId000001'), 'hello');

        self::assertSame(1, $requests);
    }

    public function testWriteDisabled(): void
    {
        $client = new MockHttpClient([]);
        $gateway = new GoogleHttpGateway($client, StaticTokenProvider::create(), new RecordingSleeper());
        $appender = new GoogleDocumentAppender($gateway, $this->config(writeEnabled: false, appendAllowedIds: ['docId000001']));

        $this->expectException(WriteDisabled::class);
        $appender->appendText(new DriveId('docId000001'), 'hello');
    }

    public function testAppendNotAllowed(): void
    {
        $client = new MockHttpClient([]);
        $gateway = new GoogleHttpGateway($client, StaticTokenProvider::create(), new RecordingSleeper());
        $appender = new GoogleDocumentAppender($gateway, $this->config(writeEnabled: true, appendAllowedIds: ['otherDoc001']));

        $this->expectException(AppendNotAllowed::class);
        $appender->appendText(new DriveId('docId000001'), 'hello');
    }

    public function testInputTooLarge(): void
    {
        $client = new MockHttpClient([]);
        $gateway = new GoogleHttpGateway($client, StaticTokenProvider::create(), new RecordingSleeper());
        $appender = new GoogleDocumentAppender($gateway, $this->config(
            writeEnabled: true,
            appendAllowedIds: ['docId000001'],
            maxAppendChars: 3,
        ));

        $this->expectException(InputTooLarge::class);
        $appender->appendText(new DriveId('docId000001'), 'abcd');
    }

    /**
     * @param list<string> $appendAllowedIds
     */
    private function config(
        bool $writeEnabled,
        array $appendAllowedIds,
        int $maxAppendChars = 5000,
    ): DocsConfig {
        return DocsConfig::fromMap([
            'GOOGLE_APPLICATION_CREDENTIALS' => '/secrets/sa.json',
            'DOCS_ROOT_FOLDER_ID' => 'rootFolder01',
            'DOCS_WRITE_ENABLED' => $writeEnabled ? 'true' : 'false',
            'DOCS_APPEND_ALLOWED_IDS' => implode(',', $appendAllowedIds),
            'DOCS_MAX_APPEND_CHARS' => (string) $maxAppendChars,
        ]);
    }
}

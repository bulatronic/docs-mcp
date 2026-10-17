<?php

declare(strict_types=1);

namespace DocsMcp\Tests\Unit;

use DocsMcp\Domain\Drive\DriveId;
use DocsMcp\Infrastructure\Google\GoogleDocumentAppender;
use DocsMcp\Infrastructure\Google\GoogleHttpGateway;
use DocsMcp\Tests\Support\RecordingSleeper;
use DocsMcp\Tests\Support\StaticTokenProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GoogleDocumentAppenderTest extends TestCase
{
    public function testDelegatesToGatewayInsertTextAtEnd(): void
    {
        $bodies = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$bodies): MockResponse {
            $body = $options['json'] ?? null;
            if ($body === null && isset($options['body']) && is_string($options['body'])) {
                $body = json_decode($options['body'], true, 512, \JSON_THROW_ON_ERROR);
            }
            $bodies[] = $body;

            return new MockResponse('{}');
        });

        $appender = new GoogleDocumentAppender(
            new GoogleHttpGateway($client, StaticTokenProvider::create(), new RecordingSleeper()),
        );
        $appender->appendText(new DriveId('docId000001'), "\nhello\n");

        self::assertCount(1, $bodies);
        self::assertSame("\nhello\n", $bodies[0]['requests'][0]['insertText']['text']);
    }
}

<?php

declare(strict_types=1);

namespace DocsMcp\Tests\Unit;

use DocsMcp\Domain\Drive\DriveId;
use DocsMcp\Infrastructure\Google\GoogleDocumentTextReader;
use DocsMcp\Infrastructure\Google\GoogleHttpGateway;
use DocsMcp\Tests\Support\RecordingSleeper;
use DocsMcp\Tests\Support\StaticTokenProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GoogleDocumentTextReaderTest extends TestCase
{
    public function testReadsPlainTextFromDocumentsGet(): void
    {
        $fixture = file_get_contents(dirname(__DIR__) . '/Fixtures/docs/sample_document.json');
        self::assertNotFalse($fixture);

        $client = new MockHttpClient(new MockResponse($fixture));
        $reader = new GoogleDocumentTextReader(
            new GoogleHttpGateway($client, StaticTokenProvider::create(), new RecordingSleeper()),
        );

        $document = $reader->read(new DriveId('sampleDoc01'));

        self::assertSame('sampleDoc01', $document->id->value);
        self::assertSame('Sample', $document->title);
        self::assertSame(
            "Hello paragraph\n# Section title\nA1\tB1\nA2\tB2\nTrailing line",
            $document->text,
        );
    }
}

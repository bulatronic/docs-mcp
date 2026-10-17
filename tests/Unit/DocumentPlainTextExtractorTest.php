<?php

declare(strict_types=1);

namespace DocsMcp\Tests\Unit;

use DocsMcp\Infrastructure\Google\DocumentPlainTextExtractor;
use PHPUnit\Framework\TestCase;

final class DocumentPlainTextExtractorTest extends TestCase
{
    public function testExtractsParagraphsTableAndHeadingFromFixture(): void
    {
        $path = dirname(__DIR__) . '/Fixtures/docs/sample_document.json';
        $json = file_get_contents($path);
        self::assertNotFalse($json);

        /** @var array<string, mixed> $document */
        $document = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        $text = (new DocumentPlainTextExtractor())->extract($document);

        self::assertSame(
            "Hello paragraph\n# Section title\nA1\tB1\nA2\tB2\nTrailing line",
            $text,
        );
        self::assertStringNotContainsString('Ignored tab', $text);
        self::assertStringNotContainsString('obj.1', $text);
    }
}

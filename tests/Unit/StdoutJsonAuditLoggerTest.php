<?php

declare(strict_types=1);

namespace DocsMcp\Tests\Unit;

use DocsMcp\Mcp\Audit\StdoutJsonAuditLogger;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class StdoutJsonAuditLoggerTest extends TestCase
{
    /**
     * @throws \JsonException
     */
    public function testWritesJsonLineWithRequiredFieldsOnly(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);

        $logger = new StdoutJsonAuditLogger($stream);
        $logger->log('list_documents', null, 'ok', 12, '2026-10-17T09:00:00+00:00');

        rewind($stream);
        $line = trim((string) stream_get_contents($stream));
        fclose($stream);

        $decoded = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame([
            'time' => '2026-10-17T09:00:00+00:00',
            'tool' => 'list_documents',
            'document_id' => null,
            'status' => 'ok',
            'duration_ms' => 12,
        ], $decoded);
        self::assertArrayNotHasKey('error_class', $decoded);
        self::assertStringNotContainsString('private_key', $line);
        self::assertStringNotContainsString('SECRET', $line);
    }

    /**
     * @throws \JsonException
     */
    public function testErrorStatusIncludesErrorClassWithoutMessageOrSecrets(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);

        $logger = new StdoutJsonAuditLogger($stream);
        $logger->log(
            'get_document_content',
            '1aBcDeFgHiJ',
            'error',
            3,
            '2026-10-17T09:00:00+00:00',
            errorClass: InvalidArgumentException::class,
        );

        rewind($stream);
        $line = trim((string) stream_get_contents($stream));
        fclose($stream);

        $decoded = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame([
            'time' => '2026-10-17T09:00:00+00:00',
            'tool' => 'get_document_content',
            'document_id' => '1aBcDeFgHiJ',
            'status' => 'error',
            'duration_ms' => 3,
            'error_class' => InvalidArgumentException::class,
        ], $decoded);
        self::assertStringNotContainsString('body', $line);
        self::assertStringNotContainsString('private_key', $line);
        self::assertStringNotContainsString('SECRET', $line);
        self::assertStringNotContainsString('GOOGLE_APPLICATION_CREDENTIALS', $line);
    }

    public function testDefaultStreamUsesPhpStdoutNotStdoutConstant(): void
    {
        $logger = new StdoutJsonAuditLogger();
        // Construction must not reference CLI-only STDOUT (FrankenPHP web SAPI).
        $logger->log('list_documents', null, 'ok', 1);
        self::assertTrue(true);
    }

    /**
     * @throws \JsonException
     */
    public function testAppendedCharsIncludedWithoutTextPayload(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);

        $logger = new StdoutJsonAuditLogger($stream);
        $logger->log(
            'append_to_document',
            'allowedDoc1',
            'ok',
            4,
            '2026-10-17T09:00:00+00:00',
            appendedChars: 42,
        );

        rewind($stream);
        $line = trim((string) stream_get_contents($stream));
        fclose($stream);

        $decoded = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(42, $decoded['appended_chars']);
        self::assertSame('ok', $decoded['status']);
        self::assertArrayNotHasKey('text', $decoded);
        self::assertArrayNotHasKey('content', $decoded);
    }
}

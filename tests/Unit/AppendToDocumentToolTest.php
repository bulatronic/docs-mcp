<?php

declare(strict_types=1);

namespace DocsMcp\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use DocsMcp\Application\Policy\RootFolderPolicy;
use DocsMcp\Application\UseCase\AppendToDocument;
use DocsMcp\Domain\Drive\DriveId;
use DocsMcp\Domain\Drive\DriveItem;
use DocsMcp\Domain\Drive\DriveMimeType;
use DocsMcp\Domain\Error\WriteDisabled;
use DocsMcp\Mcp\Audit\StdoutJsonAuditLogger;
use DocsMcp\Mcp\GuardedReferenceHandler;
use DocsMcp\Mcp\RateLimit\FileRateLimiter;
use DocsMcp\Mcp\Tool\AppendToDocumentTool;
use DocsMcp\Tests\Support\FixedClock;
use DocsMcp\Tests\Support\InMemoryDocumentAppender;
use DocsMcp\Tests\Support\InMemoryDriveMetadataReader;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Tool;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AppendToDocumentToolTest extends TestCase
{
    private string $ratePath;

    /** @var resource */
    private $auditStream;

    protected function setUp(): void
    {
        $this->ratePath = sys_get_temp_dir() . '/docs-mcp-append-tool-' . uniqid('', true) . '.json';
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $this->auditStream = $stream;
    }

    protected function tearDown(): void
    {
        if (is_file($this->ratePath)) {
            unlink($this->ratePath);
        }
        fclose($this->auditStream);
    }

    public function testDescriptionMentionsNonIdempotencyInRussian(): void
    {
        $method = new ReflectionMethod(AppendToDocumentTool::class, 'appendToDocument');
        $attributes = $method->getAttributes(McpTool::class);
        self::assertCount(1, $attributes);
        /** @var McpTool $mcpTool */
        $mcpTool = $attributes[0]->newInstance();
        self::assertSame('append_to_document', $mcpTool->name);
        self::assertNotNull($mcpTool->description);
        self::assertStringContainsString('идемпотент', mb_strtolower($mcpTool->description));
        self::assertMatchesRegularExpression('/[А-Яа-яЁё]/', $mcpTool->description);
    }

    public function testSuccessAuditsAppendedCharsWithoutText(): void
    {
        $rootId = new DriveId('rootFolder01');
        $docId = new DriveId('allowedDoc1');
        $metadata = new InMemoryDriveMetadataReader([
            new DriveItem($rootId, 'Root', DriveMimeType::FOLDER, [], false, null),
            new DriveItem($docId, 'Doc', DriveMimeType::DOCUMENT, [$rootId], false, new DateTimeImmutable('2026-10-17T12:00:00+00:00')),
        ]);
        $appender = new InMemoryDocumentAppender();
        $tool = new AppendToDocumentTool(new AppendToDocument(
            $appender,
            $metadata,
            new RootFolderPolicy($metadata, $rootId),
            new FixedClock(new DateTimeImmutable('2026-10-17 15:30:00', new DateTimeZone('Europe/Moscow'))),
            true,
            ['allowedDoc1'],
            5000,
        ));

        $inner = new class ($tool) implements ReferenceHandlerInterface {
            public function __construct(private AppendToDocumentTool $tool) {}

            public function handle(ElementReference $reference, array $arguments): mixed
            {
                return $this->tool->appendToDocument(
                    (string) $arguments['document_id'],
                    (string) $arguments['text'],
                    isset($arguments['heading']) ? (string) $arguments['heading'] : null,
                );
            }
        };

        $handler = new GuardedReferenceHandler(
            $inner,
            new FileRateLimiter($this->ratePath, maxCalls: 60, windowSeconds: 60),
            new StdoutJsonAuditLogger($this->auditStream),
        );

        $result = $handler->handle($this->toolRef(), [
            'document_id' => 'allowedDoc1',
            'text' => 'SECRET BODY TEXT',
            'heading' => 'Note',
        ]);

        self::assertSame('allowedDoc1', $result['document_id']);
        self::assertArrayHasKey('appended_chars', $result);

        rewind($this->auditStream);
        $line = trim((string) stream_get_contents($this->auditStream));
        $decoded = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('append_to_document', $decoded['tool']);
        self::assertSame('allowedDoc1', $decoded['document_id']);
        self::assertSame('ok', $decoded['status']);
        self::assertSame($result['appended_chars'], $decoded['appended_chars']);
        self::assertStringNotContainsString('SECRET BODY TEXT', $line);
        self::assertStringNotContainsString('Note', json_encode($decoded) ?: '');
    }

    public function testWriteDisabledBecomesToolError(): void
    {
        $rootId = new DriveId('rootFolder01');
        $docId = new DriveId('allowedDoc1');
        $metadata = new InMemoryDriveMetadataReader([
            new DriveItem($rootId, 'Root', DriveMimeType::FOLDER, [], false, null),
            new DriveItem($docId, 'Doc', DriveMimeType::DOCUMENT, [$rootId], false, null),
        ]);
        $tool = new AppendToDocumentTool(new AppendToDocument(
            new InMemoryDocumentAppender(),
            $metadata,
            new RootFolderPolicy($metadata, $rootId),
            new FixedClock(new DateTimeImmutable('2026-10-17 15:30:00')),
            false,
            ['allowedDoc1'],
            5000,
        ));

        $inner = new class ($tool) implements ReferenceHandlerInterface {
            public function __construct(private AppendToDocumentTool $tool) {}

            public function handle(ElementReference $reference, array $arguments): mixed
            {
                return $this->tool->appendToDocument((string) $arguments['document_id'], (string) $arguments['text']);
            }
        };

        $handler = new GuardedReferenceHandler(
            $inner,
            new FileRateLimiter($this->ratePath, maxCalls: 60, windowSeconds: 60),
            new StdoutJsonAuditLogger($this->auditStream),
        );

        try {
            $handler->handle($this->toolRef(), ['document_id' => 'allowedDoc1', 'text' => 'x']);
            self::fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            self::assertSame('Document writes are disabled', $e->getMessage());
        }

        rewind($this->auditStream);
        $decoded = json_decode(trim((string) stream_get_contents($this->auditStream)), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('error', $decoded['status']);
        self::assertSame(WriteDisabled::class, $decoded['error_class']);
        self::assertArrayNotHasKey('appended_chars', $decoded);
    }

    private function toolRef(): ToolReference
    {
        return new ToolReference(
            new Tool(
                name: 'append_to_document',
                title: null,
                inputSchema: [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                    'required' => null,
                ],
                description: null,
                annotations: null,
            ),
            [AppendToDocumentTool::class, 'appendToDocument'],
        );
    }
}

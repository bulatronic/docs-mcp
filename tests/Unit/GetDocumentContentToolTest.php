<?php

declare(strict_types=1);

namespace DocsMcp\Tests\Unit;

use DocsMcp\Application\Policy\RootFolderPolicy;
use DocsMcp\Application\UseCase\GetDocumentContent;
use DocsMcp\Domain\Drive\Document;
use DocsMcp\Domain\Drive\DocumentContentResult;
use DocsMcp\Domain\Drive\DriveId;
use DocsMcp\Domain\Drive\DriveItem;
use DocsMcp\Domain\Drive\DriveMimeType;
use DocsMcp\Domain\Error\PublicErrorMessage;
use DocsMcp\Mcp\Audit\StdoutJsonAuditLogger;
use DocsMcp\Mcp\GuardedReferenceHandler;
use DocsMcp\Mcp\RateLimit\FileRateLimiter;
use DocsMcp\Mcp\Tool\GetDocumentContentTool;
use DocsMcp\Tests\Support\InMemoryDocumentTextReader;
use DocsMcp\Tests\Support\InMemoryDriveMetadataReader;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Tool;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class GetDocumentContentToolTest extends TestCase
{
    private string $ratePath;

    /** @var resource */
    private $auditStream;

    protected function setUp(): void
    {
        $this->ratePath = sys_get_temp_dir() . '/docs-mcp-get-tool-' . uniqid('', true) . '.json';
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

    public function testDescriptionIsRussianAndDocumentIdIsRequired(): void
    {
        $method = new ReflectionMethod(GetDocumentContentTool::class, 'getDocumentContent');
        $attributes = $method->getAttributes(McpTool::class);
        self::assertCount(1, $attributes);
        /** @var McpTool $mcpTool */
        $mcpTool = $attributes[0]->newInstance();
        self::assertSame('get_document_content', $mcpTool->name);
        self::assertNotNull($mcpTool->description);
        self::assertMatchesRegularExpression('/[А-Яа-яЁё]/', $mcpTool->description);

        $documentId = $method->getParameters()[0];
        self::assertSame('document_id', $documentId->getName());
        self::assertFalse($documentId->allowsNull());
        self::assertFalse($documentId->isDefaultValueAvailable());
    }

    public function testReturnsEnvelopeAndMapsValidationErrors(): void
    {
        $rootId = new DriveId('rootFolder01');
        $docId = new DriveId('readDoc0001');
        $metadata = new InMemoryDriveMetadataReader([
            new DriveItem($rootId, 'Root', DriveMimeType::FOLDER, [], false, null),
            new DriveItem($docId, 'Doc', DriveMimeType::DOCUMENT, [$rootId], false, null),
        ]);
        $texts = new InMemoryDocumentTextReader([
            new Document($docId, 'Title', 'hello world', null),
        ]);
        $tool = new GetDocumentContentTool(new GetDocumentContent(
            $texts,
            new RootFolderPolicy($metadata, $rootId),
            100,
        ));

        $payload = $tool->getDocumentContent('readDoc0001', 5);
        self::assertSame('readDoc0001', $payload['document_id']);
        self::assertTrue($payload['truncated']);
        self::assertSame('hello', $payload['content']);
        self::assertSame(DocumentContentResult::UNTRUSTED_NOTICE, $payload['notice']);

        $inner = new class ($tool) implements ReferenceHandlerInterface {
            public function __construct(private GetDocumentContentTool $tool) {}

            public function handle(ElementReference $reference, array $arguments): mixed
            {
                return $this->tool->getDocumentContent(
                    (string) $arguments['document_id'],
                    isset($arguments['max_chars']) ? (int) $arguments['max_chars'] : null,
                );
            }
        };

        $handler = new GuardedReferenceHandler(
            $inner,
            new FileRateLimiter($this->ratePath, maxCalls: 60, windowSeconds: 60),
            new StdoutJsonAuditLogger($this->auditStream),
        );

        try {
            $handler->handle($this->toolRef(), ['document_id' => 'nope']);
            self::fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('document_id is not a valid Drive id', $e->getMessage());
        }
    }

    public function testMissingDocumentUsesSharedPublicMessage(): void
    {
        $rootId = new DriveId('rootFolder01');
        $metadata = new InMemoryDriveMetadataReader([
            new DriveItem($rootId, 'Root', DriveMimeType::FOLDER, [], false, null),
        ]);
        $tool = new GetDocumentContentTool(new GetDocumentContent(
            new InMemoryDocumentTextReader(),
            new RootFolderPolicy($metadata, $rootId),
            100,
        ));

        $inner = new class ($tool) implements ReferenceHandlerInterface {
            public function __construct(private GetDocumentContentTool $tool) {}

            public function handle(ElementReference $reference, array $arguments): mixed
            {
                return $this->tool->getDocumentContent((string) $arguments['document_id']);
            }
        };

        $handler = new GuardedReferenceHandler(
            $inner,
            new FileRateLimiter($this->ratePath, maxCalls: 60, windowSeconds: 60),
            new StdoutJsonAuditLogger($this->auditStream),
        );

        try {
            $handler->handle($this->toolRef(), ['document_id' => 'missingDoc1']);
            self::fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            self::assertSame(PublicErrorMessage::NOT_FOUND_OR_OUTSIDE_ROOT, $e->getMessage());
        }
    }

    private function toolRef(): ToolReference
    {
        return new ToolReference(
            new Tool(
                name: 'get_document_content',
                title: null,
                inputSchema: [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                    'required' => null,
                ],
                description: null,
                annotations: null,
            ),
            [GetDocumentContentTool::class, 'getDocumentContent'],
        );
    }
}

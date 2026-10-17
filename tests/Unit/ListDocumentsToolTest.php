<?php

declare(strict_types=1);

namespace DocsMcp\Tests\Unit;

use DocsMcp\Application\Policy\RootFolderPolicy;
use DocsMcp\Application\UseCase\ListDocuments;
use DocsMcp\Domain\Drive\DriveId;
use DocsMcp\Domain\Drive\DriveItem;
use DocsMcp\Domain\Drive\DriveMimeType;
use DocsMcp\Domain\Error\PublicErrorMessage;
use DocsMcp\Mcp\Audit\StdoutJsonAuditLogger;
use DocsMcp\Mcp\GuardedReferenceHandler;
use DocsMcp\Mcp\RateLimit\FileRateLimiter;
use DocsMcp\Mcp\Tool\ListDocumentsTool;
use DocsMcp\Tests\Support\InMemoryDriveMetadataReader;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Tool;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ListDocumentsToolTest extends TestCase
{
    private string $ratePath;

    /** @var resource */
    private $auditStream;

    protected function setUp(): void
    {
        $this->ratePath = sys_get_temp_dir() . '/docs-mcp-list-tool-' . uniqid('', true) . '.json';
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

    public function testDescriptionIsRussianAndArgumentsAreTyped(): void
    {
        $method = new ReflectionMethod(ListDocumentsTool::class, 'listDocuments');
        $attributes = $method->getAttributes(McpTool::class);
        self::assertCount(1, $attributes);
        /** @var McpTool $mcpTool */
        $mcpTool = $attributes[0]->newInstance();
        self::assertSame('list_documents', $mcpTool->name);
        self::assertNotNull($mcpTool->description);
        self::assertMatchesRegularExpression('/[А-Яа-яЁё]/', $mcpTool->description);

        $params = [];
        foreach ($method->getParameters() as $parameter) {
            $params[$parameter->getName()] = $parameter;
        }
        self::assertArrayHasKey('folder_id', $params);
        self::assertArrayHasKey('query', $params);
        self::assertArrayHasKey('limit', $params);
        self::assertTrue($params['folder_id']->allowsNull());
        self::assertTrue($params['query']->allowsNull());
        self::assertSame(50, $params['limit']->getDefaultValue());
    }

    public function testMapsResultAndInvalidIdBecomesToolError(): void
    {
        $rootId = new DriveId('rootFolder01');
        $reader = new InMemoryDriveMetadataReader([
            new DriveItem($rootId, 'Root', DriveMimeType::FOLDER, [], false, null),
            new DriveItem(new DriveId('docOk00001'), 'OK', DriveMimeType::DOCUMENT, [$rootId], false, null),
        ]);
        $tool = new ListDocumentsTool(new ListDocuments(
            $reader,
            new RootFolderPolicy($reader, $rootId),
            $rootId,
        ));

        $payload = $tool->listDocuments();
        self::assertSame('docOk00001', $payload['documents'][0]['id']);
        self::assertSame('', $payload['documents'][0]['path']);

        $inner = new class ($tool) implements ReferenceHandlerInterface {
            public function __construct(private ListDocumentsTool $tool) {}

            public function handle(\Mcp\Capability\Registry\ElementReference $reference, array $arguments): mixed
            {
                return $this->tool->listDocuments(
                    $arguments['folder_id'] ?? null,
                    $arguments['query'] ?? null,
                    $arguments['limit'] ?? 50,
                );
            }
        };

        $handler = new GuardedReferenceHandler(
            $inner,
            new FileRateLimiter($this->ratePath, maxCalls: 60, windowSeconds: 60),
            new StdoutJsonAuditLogger($this->auditStream),
        );

        try {
            $handler->handle($this->toolRef(), ['folder_id' => 'bad']);
            self::fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('folder_id is not a valid Drive id', $e->getMessage());
        }
    }

    public function testOutsideRootMessageIsShared(): void
    {
        $rootId = new DriveId('rootFolder01');
        $other = new DriveId('otherRoot01');
        $reader = new InMemoryDriveMetadataReader([
            new DriveItem($rootId, 'Root', DriveMimeType::FOLDER, [], false, null),
            new DriveItem($other, 'Other', DriveMimeType::FOLDER, [], false, null),
        ]);
        $tool = new ListDocumentsTool(new ListDocuments(
            $reader,
            new RootFolderPolicy($reader, $rootId),
            $rootId,
        ));

        $inner = new class ($tool) implements ReferenceHandlerInterface {
            public function __construct(private ListDocumentsTool $tool) {}

            public function handle(\Mcp\Capability\Registry\ElementReference $reference, array $arguments): mixed
            {
                return $this->tool->listDocuments($arguments['folder_id'] ?? null);
            }
        };

        $handler = new GuardedReferenceHandler(
            $inner,
            new FileRateLimiter($this->ratePath, maxCalls: 60, windowSeconds: 60),
            new StdoutJsonAuditLogger($this->auditStream),
        );

        try {
            $handler->handle($this->toolRef(), ['folder_id' => 'otherRoot01']);
            self::fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            self::assertSame(PublicErrorMessage::NOT_FOUND_OR_OUTSIDE_ROOT, $e->getMessage());
        }
    }

    private function toolRef(): ToolReference
    {
        return new ToolReference(
            new Tool(
                name: 'list_documents',
                title: null,
                inputSchema: [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                    'required' => null,
                ],
                description: null,
                annotations: null,
            ),
            [ListDocumentsTool::class, 'listDocuments'],
        );
    }
}

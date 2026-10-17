<?php

declare(strict_types=1);

namespace DocsMcp\Tests\Security;

use DateTimeImmutable;
use DateTimeZone;
use DocsMcp\Application\Policy\RootFolderPolicy;
use DocsMcp\Application\UseCase\AppendToDocument;
use DocsMcp\Application\UseCase\GetDocumentContent;
use DocsMcp\Application\UseCase\ListDocuments;
use DocsMcp\Domain\Drive\Document;
use DocsMcp\Domain\Drive\DriveId;
use DocsMcp\Domain\Drive\DriveItem;
use DocsMcp\Domain\Drive\DriveMimeType;
use DocsMcp\Mcp\Audit\StdoutJsonAuditLogger;
use DocsMcp\Mcp\GuardedReferenceHandler;
use DocsMcp\Mcp\RateLimit\FileRateLimiter;
use DocsMcp\Mcp\Tool\AppendToDocumentTool;
use DocsMcp\Mcp\Tool\GetDocumentContentTool;
use DocsMcp\Mcp\Tool\ListDocumentsTool;
use DocsMcp\Tests\Support\FixedClock;
use DocsMcp\Tests\Support\InMemoryDocumentAppender;
use DocsMcp\Tests\Support\InMemoryDocumentTextReader;
use DocsMcp\Tests\Support\InMemoryDriveMetadataReader;
use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Tool;
use PHPUnit\Framework\TestCase;

/**
 * All tools run through GuardedReferenceHandler; secrets must not appear in audit or errors.
 */
final class SecretsNotInLogsTest extends TestCase
{
    private const string TOKEN = 'ya29.SECRET-TOKEN-VALUE-DO-NOT-LEAK';

    private const string PRIVATE_KEY = '-----BEGIN PRIVATE KEY-----\nSECRET_KEY_MATERIAL\n-----END PRIVATE KEY-----';

    private const string DOC_BODY = 'UNTRUSTED_DOCUMENT_BODY_WITH_SECRET_INSTRUCTION';

    private string $ratePath;

    /** @var resource */
    private $auditStream;

    protected function setUp(): void
    {
        $this->ratePath = sys_get_temp_dir() . '/docs-mcp-secrets-' . uniqid('', true) . '.json';
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

    public function testToolSuccessAndErrorPathsDoNotLeakSecrets(): void
    {
        $rootId = new DriveId('rootFolder01');
        $docId = new DriveId('allowedDoc1');
        $metadata = new InMemoryDriveMetadataReader([
            new DriveItem($rootId, 'Root', DriveMimeType::FOLDER, [], false, null),
            new DriveItem($docId, 'Doc ' . self::TOKEN, DriveMimeType::DOCUMENT, [$rootId], false, new DateTimeImmutable('2026-01-01T00:00:00+00:00')),
        ]);
        $texts = new InMemoryDocumentTextReader([
            new Document($docId, 'Title', self::DOC_BODY . "\n" . self::PRIVATE_KEY, null),
        ]);
        $appender = new InMemoryDocumentAppender();

        $list = new ListDocumentsTool(new ListDocuments(
            $metadata,
            new RootFolderPolicy($metadata, $rootId),
            $rootId,
        ));
        $get = new GetDocumentContentTool(new GetDocumentContent(
            $texts,
            new RootFolderPolicy($metadata, $rootId),
            500,
        ));
        $append = new AppendToDocumentTool(new AppendToDocument(
            $appender,
            $metadata,
            new RootFolderPolicy($metadata, $rootId),
            new FixedClock(new DateTimeImmutable('2026-10-17 15:30:00', new DateTimeZone('UTC'))),
            true,
            ['allowedDoc1'],
            5000,
        ));

        $inner = new class ($list, $get, $append) implements ReferenceHandlerInterface {
            public function __construct(
                private ListDocumentsTool $list,
                private GetDocumentContentTool $get,
                private AppendToDocumentTool $append,
            ) {}

            public function handle(ElementReference $reference, array $arguments): mixed
            {
                if (!$reference instanceof ToolReference) {
                    return null;
                }

                return match ($reference->tool->name) {
                    'list_documents' => $this->list->listDocuments(
                        $arguments['folder_id'] ?? null,
                        $arguments['query'] ?? null,
                        $arguments['limit'] ?? 50,
                    ),
                    'get_document_content' => $this->get->getDocumentContent(
                        (string) $arguments['document_id'],
                        isset($arguments['max_chars']) ? (int) $arguments['max_chars'] : null,
                    ),
                    'append_to_document' => $this->append->appendToDocument(
                        (string) $arguments['document_id'],
                        (string) $arguments['text'],
                        isset($arguments['heading']) ? (string) $arguments['heading'] : null,
                    ),
                    default => throw new \RuntimeException('unknown tool'),
                };
            }
        };

        $handler = new GuardedReferenceHandler(
            $inner,
            new FileRateLimiter($this->ratePath, maxCalls: 60, windowSeconds: 60),
            new StdoutJsonAuditLogger($this->auditStream),
        );

        $handler->handle($this->toolRef('list_documents'), []);
        $getResult = $handler->handle($this->toolRef('get_document_content'), ['document_id' => 'allowedDoc1']);
        self::assertSame(self::DOC_BODY . "\n" . self::PRIVATE_KEY, $getResult['content']);

        $handler->handle($this->toolRef('append_to_document'), [
            'document_id' => 'allowedDoc1',
            'text' => self::DOC_BODY,
            'heading' => self::TOKEN,
        ]);

        $errorMessages = [];
        try {
            $handler->handle($this->toolRef('get_document_content'), ['document_id' => 'missingDoc1']);
        } catch (ToolCallException $e) {
            $errorMessages[] = $e->getMessage();
        }
        try {
            $handler->handle($this->toolRef('append_to_document'), [
                'document_id' => 'allowedDoc1',
                'text' => '',
            ]);
        } catch (ToolCallException $e) {
            $errorMessages[] = $e->getMessage();
        }

        rewind($this->auditStream);
        $audit = (string) stream_get_contents($this->auditStream);
        $blob = $audit . "\n" . implode("\n", $errorMessages);

        self::assertStringNotContainsString(self::TOKEN, $audit);
        self::assertStringNotContainsString(self::PRIVATE_KEY, $blob);
        self::assertStringNotContainsString('BEGIN PRIVATE KEY', $audit);
        self::assertStringNotContainsString(self::DOC_BODY, $audit);
        foreach ($errorMessages as $message) {
            self::assertStringNotContainsString(self::TOKEN, $message);
            self::assertStringNotContainsString(self::PRIVATE_KEY, $message);
            self::assertStringNotContainsString(self::DOC_BODY, $message);
        }

        // Tool response may contain document content by contract; audit must not.
        self::assertStringContainsString('"tool":"append_to_document"', $audit);
        self::assertStringContainsString('"appended_chars"', $audit);
    }

    private function toolRef(string $name): ToolReference
    {
        return new ToolReference(
            new Tool(
                name: $name,
                title: null,
                inputSchema: [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                    'required' => null,
                ],
                description: null,
                annotations: null,
            ),
            [self::class, 'dummy'],
        );
    }

    public static function dummy(): array
    {
        return [];
    }
}

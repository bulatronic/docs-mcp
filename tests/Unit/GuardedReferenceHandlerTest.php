<?php

declare(strict_types=1);

namespace DocsMcp\Tests\Unit;

use DocsMcp\Mcp\Audit\StdoutJsonAuditLogger;
use DocsMcp\Mcp\GuardedReferenceHandler;
use DocsMcp\Mcp\RateLimit\FileRateLimiter;
use InvalidArgumentException;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Exception\RegistryException;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Tool;
use PHPUnit\Framework\TestCase;

final class GuardedReferenceHandlerTest extends TestCase
{
    private string $ratePath;

    /** @var resource */
    private $auditStream;

    protected function setUp(): void
    {
        $this->ratePath = sys_get_temp_dir() . '/docs-mcp-guard-rate-' . uniqid('', true) . '.json';
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

    /**
     * @throws RegistryException
     * @throws \JsonException
     */
    public function testLogsSuccessWithoutSecretsFromArguments(): void
    {
        $inner = $this->createMock(ReferenceHandlerInterface::class);
        $inner->method('handle')->willReturn(['ok' => true]);

        $handler = $this->handler($inner);
        $result = $handler->handle($this->toolRef('get_document_content'), [
            'document_id' => '1aBcDeFgHiJ',
            'body' => 'SECRET BODY private_key=super-secret',
        ]);

        self::assertSame(['ok' => true], $result);
        $line = $this->readAuditLine();
        self::assertSame('get_document_content', $line['tool']);
        self::assertSame('1aBcDeFgHiJ', $line['document_id']);
        self::assertSame('ok', $line['status']);
        self::assertArrayHasKey('duration_ms', $line);
        $encoded = json_encode($line) ?: '';
        self::assertStringNotContainsString('SECRET', $encoded);
        self::assertStringNotContainsString('private_key', $encoded);
    }

    /**
     * @throws RegistryException
     * @throws \JsonException
     */
    public function testDoesNotLogDocumentIdThatIsNotAResourceId(): void
    {
        $inner = $this->createMock(ReferenceHandlerInterface::class);
        $inner->method('handle')->willReturn(['ok' => true]);

        $handler = $this->handler($inner);
        $handler->handle($this->toolRef('append_to_document'), [
            'document_id' => 'SECRET private_key=super-secret-material',
        ]);

        $line = $this->readAuditLine();
        self::assertNull($line['document_id']);
        $encoded = json_encode($line) ?: '';
        self::assertStringNotContainsString('SECRET', $encoded);
        self::assertStringNotContainsString('private_key', $encoded);
    }

    /**
     * @throws RegistryException
     * @throws \JsonException
     */
    public function testConvertsInvalidArgumentToToolCallExceptionWithoutStackOrSecrets(): void
    {
        $inner = $this->createMock(ReferenceHandlerInterface::class);
        $inner->method('handle')->willThrowException(
            new InvalidArgumentException("private_key=super-secret\ntruncated"),
        );

        $handler = $this->handler($inner);

        try {
            $handler->handle($this->toolRef('append_to_document'), ['document_id' => '1aBcDeFgHiJ']);
            self::fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            self::assertSame('[redacted] truncated', $e->getMessage());
            self::assertStringNotContainsString('super-secret', $e->getMessage());
            self::assertStringNotContainsString('GuardedReferenceHandlerTest.php', $e->getMessage());
            self::assertStringNotContainsString("\n", $e->getMessage());
        }

        $line = $this->readAuditLine();
        self::assertSame('error', $line['status']);
        self::assertSame('1aBcDeFgHiJ', $line['document_id']);
        self::assertSame(InvalidArgumentException::class, $line['error_class']);
        $encoded = json_encode($line) ?: '';
        self::assertStringNotContainsString('super-secret', $encoded);
        self::assertStringNotContainsString(__FILE__, $encoded);
    }

    /**
     * @throws RegistryException
     */
    /**
     * @throws \JsonException
     * @throws RegistryException
     */
    public function testHidesInternalExceptionDetails(): void
    {
        $inner = $this->createMock(ReferenceHandlerInterface::class);
        $inner->method('handle')->willThrowException(new \RuntimeException('password=super-secret boom'));

        $handler = $this->handler($inner);

        try {
            $handler->handle($this->toolRef('list_documents'), []);
            self::fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            self::assertSame('Internal error while executing tool', $e->getMessage());
            self::assertStringNotContainsString('super-secret', $e->getMessage());
        }

        $encoded = json_encode($this->readAuditLine(), \JSON_THROW_ON_ERROR) ?: '';
        self::assertStringNotContainsString('super-secret', $encoded);
        self::assertStringNotContainsString('password', $encoded);
    }

    /**
     * @throws RegistryException
     */
    public function testRateLimitsToolCalls(): void
    {
        $inner = $this->createMock(ReferenceHandlerInterface::class);
        $inner->expects(self::once())->method('handle')->willReturn(['ok' => true]);

        $handler = new GuardedReferenceHandler(
            $inner,
            new FileRateLimiter($this->ratePath, maxCalls: 1, windowSeconds: 60),
            new StdoutJsonAuditLogger($this->auditStream),
        );

        $handler->handle($this->toolRef('list_documents'), []);

        try {
            $handler->handle($this->toolRef('list_documents'), []);
            self::fail('Expected rate limit ToolCallException');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('Rate limit exceeded', $e->getMessage());
        }

        rewind($this->auditStream);
        $raw = stream_get_contents($this->auditStream) ?: '';
        self::assertStringContainsString('"status":"rate_limited"', $raw);
        self::assertStringNotContainsString('private_key', $raw);
    }

    private function handler(ReferenceHandlerInterface $inner): GuardedReferenceHandler
    {
        return new GuardedReferenceHandler(
            $inner,
            new FileRateLimiter($this->ratePath, maxCalls: 60, windowSeconds: 60),
            new StdoutJsonAuditLogger($this->auditStream),
        );
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

    /**
     * @return array{time: string, tool: string, document_id: string|null, status: string, duration_ms: int, error_class?: string}
     *
     * @throws \JsonException
     */
    private function readAuditLine(): array
    {
        rewind($this->auditStream);
        $raw = stream_get_contents($this->auditStream);
        self::assertNotFalse($raw);
        $line = trim($raw);
        self::assertNotSame('', $line);

        /** @var array{time: string, tool: string, document_id: string|null, status: string, duration_ms: int, error_class?: string} $decoded */
        $decoded = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }
}

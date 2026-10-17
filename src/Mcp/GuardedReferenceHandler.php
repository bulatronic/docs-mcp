<?php

declare(strict_types=1);

namespace DocsMcp\Mcp;

use DocsMcp\Domain\Drive\DriveId;
use DocsMcp\Domain\Error\DocsError;
use DocsMcp\Mcp\Audit\StdoutJsonAuditLogger;
use DocsMcp\Mcp\RateLimit\FileRateLimiter;
use InvalidArgumentException;
use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Exception\RegistryException;
use Mcp\Exception\ToolCallException;
use Throwable;

/**
 * Wraps tool execution with rate limiting, audit logging and safe MCP errors.
 */
final readonly class GuardedReferenceHandler implements ReferenceHandlerInterface
{
    public function __construct(
        private ReferenceHandlerInterface $inner,
        private FileRateLimiter $rateLimiter,
        private StdoutJsonAuditLogger $auditLogger,
    ) {}

    /**
     * @param array<string, mixed> $arguments
     *
     * @throws RegistryException
     */
    public function handle(ElementReference $reference, array $arguments): mixed
    {
        if (!$reference instanceof ToolReference) {
            return $this->inner->handle($reference, $arguments);
        }

        $tool = $reference->tool->name;
        $documentId = $this->extractDocumentId($arguments);
        $started = hrtime(true);

        if (!$this->rateLimiter->attempt()) {
            $this->auditLogger->log($tool, $documentId, 'rate_limited', $this->elapsedMs($started));

            throw new ToolCallException('Rate limit exceeded: max 60 tool calls per minute');
        }

        try {
            $result = $this->inner->handle($reference, $arguments);
            $this->auditLogger->log($tool, $documentId, 'ok', $this->elapsedMs($started));

            return $result;
        } catch (ToolCallException $e) {
            $this->auditLogger->log($tool, $documentId, 'error', $this->elapsedMs($started), errorClass: $e::class);
            throw new ToolCallException($this->publicMessage($e), 0, $e);
        } catch (DocsError|InvalidArgumentException $e) {
            $this->auditLogger->log($tool, $documentId, 'error', $this->elapsedMs($started), errorClass: $e::class);
            throw new ToolCallException($this->publicMessage($e), 0, $e);
        } catch (Throwable $e) {
            $this->auditLogger->log($tool, $documentId, 'error', $this->elapsedMs($started), errorClass: $e::class);
            throw new ToolCallException('Internal error while executing tool', 0, $e);
        }
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function extractDocumentId(array $arguments): ?string
    {
        if (!array_key_exists('document_id', $arguments)) {
            return null;
        }

        $value = $arguments['document_id'];
        if (!is_string($value) || !DriveId::isValid($value)) {
            return null;
        }

        return $value;
    }

    private function elapsedMs(int $startedHrtime): int
    {
        return (int) max(0, (hrtime(true) - $startedHrtime) / 1_000_000);
    }

    private function publicMessage(Throwable $e): string
    {
        $message = trim($e->getMessage());
        if ($message === '') {
            return 'Tool execution failed';
        }

        $message = preg_replace(
            '/\b(?:password|private_key|client_secret|GOOGLE_APPLICATION_CREDENTIALS)\b\s*[=:]\s*\S+/i',
            '[redacted]',
            $message,
        ) ?? $message;
        $message = str_replace(["\r", "\n", "\t"], ' ', $message);
        if (strlen($message) > 300) {
            $message = substr($message, 0, 297) . '...';
        }

        return $message;
    }
}

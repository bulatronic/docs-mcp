<?php

declare(strict_types=1);

namespace DocsMcp\Mcp\Audit;

use InvalidArgumentException;

/**
 * Writes one JSON audit line per tool call to stdout.
 * Never logs document bodies, credentials or exception messages.
 */
final class StdoutJsonAuditLogger
{
    /** @var resource */
    private $stream;

    /**
     * @param resource|null $stream
     */
    public function __construct(mixed $stream = null)
    {
        if ($stream === null) {
            // STDOUT is CLI-only; php://stdout works under FrankenPHP web SAPI too.
            $stream = fopen('php://stdout', 'wb');
        }
        if (!is_resource($stream)) {
            throw new InvalidArgumentException('Audit logger stream must be a writable resource');
        }

        $this->stream = $stream;
    }

    public function log(
        string $tool,
        ?string $documentId,
        string $status,
        int $durationMs,
        ?string $time = null,
        ?string $errorClass = null,
    ): void {
        $payload = [
            'time' => $time ?? gmdate('c'),
            'tool' => $tool,
            'document_id' => $documentId,
            'status' => $status,
            'duration_ms' => $durationMs,
        ];

        if ($status === 'error' && $errorClass !== null && $errorClass !== '') {
            $payload['error_class'] = $errorClass;
        }

        $line = json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if ($line === false) {
            return;
        }

        fwrite($this->stream, $line . "\n");
    }
}

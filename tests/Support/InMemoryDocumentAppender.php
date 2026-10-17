<?php

declare(strict_types=1);

namespace DocsMcp\Tests\Support;

use DocsMcp\Domain\Drive\DriveId;
use DocsMcp\Ports\DocumentAppender;
use Throwable;

/**
 * Records append calls; optional next error for upstream failure tests.
 */
final class InMemoryDocumentAppender implements DocumentAppender
{
    /** @var list<array{DriveId, string}> */
    public private(set) array $calls = [];

    public ?Throwable $nextError = null;

    public function appendText(DriveId $documentId, string $text): void
    {
        if ($this->nextError !== null) {
            $error = $this->nextError;
            $this->nextError = null;
            throw $error;
        }

        $this->calls[] = [$documentId, $text];
    }
}

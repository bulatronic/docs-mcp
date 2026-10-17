<?php

declare(strict_types=1);

namespace DocsMcp\Mcp\RateLimit;

use RuntimeException;

/**
 * Sliding-window rate limiter shared across PHP workers via a flock'd file.
 */
final readonly class FileRateLimiter
{
    public function __construct(
        private string $storagePath,
        private int $maxCalls,
        private int $windowSeconds = 60,
    ) {
        if ($this->maxCalls < 1) {
            throw new RuntimeException('maxCalls must be >= 1');
        }
        if ($this->windowSeconds < 1) {
            throw new RuntimeException('windowSeconds must be >= 1');
        }
    }

    /**
     * @return bool true when the call is allowed
     */
    public function attempt(): bool
    {
        $dir = dirname($this->storagePath);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create rate limit directory');
        }

        $handle = fopen($this->storagePath, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Unable to open rate limit file');
        }

        try {
            if (!flock($handle, \LOCK_EX)) {
                throw new RuntimeException('Unable to lock rate limit file');
            }

            $now = microtime(true);
            $cutoff = $now - $this->windowSeconds;
            $timestamps = $this->readTimestamps($handle);
            $timestamps = array_values(array_filter(
                $timestamps,
                static fn(float $ts): bool => $ts >= $cutoff,
            ));

            if (count($timestamps) >= $this->maxCalls) {
                $this->writeTimestamps($handle, $timestamps);

                return false;
            }

            $timestamps[] = $now;
            $this->writeTimestamps($handle, $timestamps);

            return true;
        } finally {
            flock($handle, \LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @param resource $handle
     *
     * @return list<float>
     */
    private function readTimestamps(mixed $handle): array
    {
        if (!is_resource($handle)) {
            return [];
        }

        rewind($handle);
        $raw = stream_get_contents($handle);
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $timestamps = [];
        foreach ($decoded as $value) {
            if (is_int($value) || is_float($value)) {
                $timestamps[] = (float) $value;
            } elseif (is_string($value) && is_numeric($value)) {
                $timestamps[] = (float) $value;
            }
        }

        return $timestamps;
    }

    /**
     * @param resource $handle
     * @param list<float> $timestamps
     *
     * @throws \JsonException
     */
    private function writeTimestamps(mixed $handle, array $timestamps): void
    {
        if (!is_resource($handle)) {
            return;
        }

        $json = json_encode($timestamps, \JSON_THROW_ON_ERROR);
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, $json);
        fflush($handle);
    }
}

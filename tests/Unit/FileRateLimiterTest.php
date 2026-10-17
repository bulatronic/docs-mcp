<?php

declare(strict_types=1);

namespace DocsMcp\Tests\Unit;

use DocsMcp\Mcp\RateLimit\FileRateLimiter;
use PHPUnit\Framework\TestCase;

final class FileRateLimiterTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/docs-mcp-rate-' . uniqid('', true) . '.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testAllowsUpToLimitThenRejects(): void
    {
        $limiter = new FileRateLimiter($this->path, maxCalls: 3, windowSeconds: 60);

        self::assertTrue($limiter->attempt());
        self::assertTrue($limiter->attempt());
        self::assertTrue($limiter->attempt());
        self::assertFalse($limiter->attempt());
    }
}

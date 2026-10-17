<?php

declare(strict_types=1);

namespace DocsMcp\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Ensures the HTTP surface stays MCP-only and smoke stays CLI-only.
 */
final class HttpSurfaceTest extends TestCase
{
    public function testCaddyfileExposesOnlyMcpHandle(): void
    {
        $caddy = file_get_contents(dirname(__DIR__, 2) . '/Caddyfile');
        self::assertNotFalse($caddy);
        self::assertStringContainsString('handle /mcp*', $caddy);
        self::assertStringContainsString('root * /app/public', $caddy);
        self::assertStringContainsString('respond "Not Found" 404', $caddy);
        self::assertStringNotContainsString('/bin/', $caddy);
    }

    public function testDockerfileRuntimeInstallsWithoutDevDependencies(): void
    {
        $dockerfile = file_get_contents(dirname(__DIR__, 2) . '/Dockerfile');
        self::assertNotFalse($dockerfile);
        self::assertMatchesRegularExpression('/composer install[\s\S]*--no-dev/', $dockerfile);
        self::assertMatchesRegularExpression('/dump-autoload[\s\S]*--no-dev/', $dockerfile);
        self::assertStringContainsString('AS runtime', $dockerfile);
    }

    public function testDockerignoreKeepsSecretsAndTestsOutOfImageContext(): void
    {
        $ignore = file_get_contents(dirname(__DIR__, 2) . '/.dockerignore');
        self::assertNotFalse($ignore);
        self::assertStringContainsString('secrets/', $ignore);
        self::assertStringContainsString('tests', $ignore);
        // bin/smoke.php stays in the image for CLI checks; HTTP is gated by Caddy (/mcp only).
        self::assertStringNotContainsString("bin/\n", $ignore . "\n");
    }

    public function testSmokeScriptLivesOutsidePublicWebRoot(): void
    {
        $root = dirname(__DIR__, 2);
        self::assertFileExists($root . '/bin/smoke.php');
        self::assertFileDoesNotExist($root . '/public/smoke.php');
        self::assertDirectoryDoesNotExist($root . '/public/bin');
    }
}

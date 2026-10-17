<?php

declare(strict_types=1);

namespace DocsMcp\Tests\Architecture;

use DocsMcp\Infrastructure\Google\GoogleHttpGateway;
use DocsMcp\Ports\DocumentAppender;
use DocsMcp\Ports\DocumentTextReader;
use DocsMcp\Ports\DriveMetadataReader;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use SplFileInfo;

/**
 * Fails when src/, ports, gateway or composer introduce forbidden Google Drive mutations.
 */
final class ForbiddenOperationsTest extends TestCase
{
    /** @var array<string, list<string>> */
    private const array ALLOWED_PORT_METHODS = [
        DriveMetadataReader::class => ['getItem', 'listChildren'],
        DocumentTextReader::class => ['read'],
        DocumentAppender::class => ['appendText'],
    ];

    /** @var list<string> */
    private const array ALLOWED_GATEWAY_METHODS = [
        'getFileMetadata',
        'listFiles',
        'getDocument',
        'insertTextAtEnd',
    ];

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function forbiddenSrcPatterns(): array
    {
        return [
            ['/google\/apiclient/i', 'google/apiclient'],
            ['/\bemptyTrash\b/', 'emptyTrash'],
            ['/\bdeleteContentRange\b/', 'deleteContentRange'],
            ['/\breplaceAllText\b/', 'replaceAllText'],
            ['/\bupdateTextStyle\b/', 'updateTextStyle'],
            ['/\bcreatePermission\b/i', 'createPermission'],
            ['/\bcreateFolder\b/i', 'createFolder'],
            ['/\bcreateFile\b/i', 'createFile'],
            ['/\bfiles\.(create|copy|update|delete|trash)\b/i', 'files.(create|copy|update|delete|trash)'],
            ['/\/permissions(\/|"|\'|\?|$)/i', '/permissions'],
            ['/->\s*(delete|trash|move|copy|share)\s*\(/i', '->delete|trash|move|copy|share('],
            // Whole-word API verbs; "trashed" (read-only flag) is intentionally allowed.
            ['/(?<![A-Za-z])trash(?![A-Za-z])/i', 'trash'],
            ['/(?<![A-Za-z])share(?![A-Za-z])/i', 'share'],
            ['/(?<![A-Za-z])delete(?![A-Za-z])/i', 'delete'],
            ['/(?<![A-Za-z])move(?![A-Za-z])/i', 'move'],
            ['/(?<![A-Za-z])copy(?![A-Za-z])/i', 'copy'],
            ['/\bpermissions\.create\b/i', 'permissions.create'],
            ['/\bnextPageToken\b/', 'nextPageToken'],
        ];
    }

    public function testSrcContainsNoForbiddenGoogleMutations(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        self::assertDirectoryExists($root);

        $violations = [];

        /** @var SplFileInfo $file */
        foreach ($this->phpFiles($root) as $file) {
            $path = $file->getPathname();
            $relative = substr($path, strlen(dirname($root)) + 1);
            $contents = file_get_contents($path);
            self::assertNotFalse($contents);

            foreach (self::forbiddenSrcPatterns() as [$pattern, $label]) {
                if (preg_match($pattern, $contents) === 1) {
                    $violations[] = sprintf('%s → %s', $relative, $label);
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            "Forbidden operations found in src/:\n" . implode("\n", $violations),
        );
    }

    public function testComposerDoesNotRequireGoogleApiclient(): void
    {
        $composerPath = dirname(__DIR__, 2) . '/composer.json';
        $lockPath = dirname(__DIR__, 2) . '/composer.lock';
        $composer = file_get_contents($composerPath);
        self::assertNotFalse($composer);
        self::assertStringNotContainsString('google/apiclient', $composer);

        if (is_file($lockPath)) {
            $lock = file_get_contents($lockPath);
            self::assertNotFalse($lock);
            self::assertStringNotContainsString('"name": "google/apiclient"', $lock);
        }
    }

    public function testPortInterfacesOnlyExposeAllowlistedMethods(): void
    {
        foreach (self::ALLOWED_PORT_METHODS as $interface => $allowed) {
            self::assertTrue(interface_exists($interface), $interface);
            $reflection = new ReflectionClass($interface);
            $actual = [];
            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                $actual[] = $method->getName();
            }
            sort($actual);
            $expected = $allowed;
            sort($expected);
            self::assertSame(
                $expected,
                $actual,
                sprintf(
                    'Port %s methods changed. Update ALLOWED_PORT_METHODS only after an explicit security review.',
                    $interface,
                ),
            );
        }

        $portsDir = dirname(__DIR__, 2) . '/src/Ports';
        $files = glob($portsDir . '/*.php');
        self::assertNotFalse($files);
        $discovered = [];
        foreach ($files as $file) {
            $class = $this->interfaceNameFromFile($file);
            if ($class !== null) {
                $discovered[] = $class;
            }
        }
        sort($discovered);
        $expectedInterfaces = array_keys(self::ALLOWED_PORT_METHODS);
        sort($expectedInterfaces);
        self::assertSame(
            $expectedInterfaces,
            $discovered,
            'New port interface detected. Update ALLOWED_PORT_METHODS after security review.',
        );
    }

    public function testGoogleHttpGatewayOnlyExposesAllowlistedMethods(): void
    {
        $reflection = new ReflectionClass(GoogleHttpGateway::class);
        $actual = [];
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== GoogleHttpGateway::class) {
                continue;
            }
            if ($method->isConstructor()) {
                continue;
            }
            $actual[] = $method->getName();
        }
        sort($actual);
        $expected = self::ALLOWED_GATEWAY_METHODS;
        sort($expected);
        self::assertSame(
            $expected,
            $actual,
            'GoogleHttpGateway public API changed. Update ALLOWED_GATEWAY_METHODS after security review.',
        );
    }

    private function interfaceNameFromFile(string $path): ?string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            return null;
        }
        if (!preg_match('/^namespace\s+([^;]+);/m', $contents, $nsMatch)) {
            return null;
        }
        if (!preg_match('/^interface\s+(\w+)/m', $contents, $classMatch)) {
            return null;
        }

        return $nsMatch[1] . '\\' . $classMatch[1];
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private function phpFiles(string $root): iterable
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                yield $file;
            }
        }
    }
}

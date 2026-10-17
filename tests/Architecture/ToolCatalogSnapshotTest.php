<?php

declare(strict_types=1);

namespace DocsMcp\Tests\Architecture;

use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use SplFileInfo;

/**
 * Snapshot of MCP tool names. Adding a new tool must break this test on purpose.
 */
final class ToolCatalogSnapshotTest extends TestCase
{
    private const array EXPECTED_TOOLS = [];

    public function testToolCatalogMatchesSnapshot(): void
    {
        $tools = $this->discoverToolNames();
        sort($tools);

        self::assertSame(
            self::EXPECTED_TOOLS,
            $tools,
            'MCP tool catalog changed. Update EXPECTED_TOOLS only after an explicit product decision.',
        );
    }

    /**
     * @return list<string>
     */
    private function discoverToolNames(): array
    {
        $dir = dirname(__DIR__, 2) . '/src/Mcp/Tool';
        self::assertDirectoryExists($dir);

        $names = [];

        /** @var SplFileInfo $file */
        foreach ($this->phpFiles($dir) as $file) {
            $class = $this->classNameFromFile($file->getPathname());
            if ($class === null || !class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($method->getAttributes(McpTool::class) as $attribute) {
                    /** @var McpTool $mcpTool */
                    $mcpTool = $attribute->newInstance();
                    $name = $mcpTool->name ?? $method->getName();
                    $names[] = $name;
                }
            }
        }

        return array_values(array_unique($names));
    }

    private function classNameFromFile(string $path): ?string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        if (!preg_match('/^namespace\s+([^;]+);/m', $contents, $nsMatch)) {
            return null;
        }
        if (!preg_match('/^(?:final\s+)?(?:readonly\s+)?class\s+(\w+)/m', $contents, $classMatch)) {
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

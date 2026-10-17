<?php

declare(strict_types=1);

namespace DocsMcp\Tests\Unit;

use DocsMcp\Infrastructure\Config\DocsConfig;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DocsConfigTest extends TestCase
{
    private const string SECRET = 'super-secret-key-material/with-slash';

    private const string ROOT_ID = '1aBcDeFgHiJ';

    public function testValidConfigurationMapsAllFields(): void
    {
        $config = DocsConfig::fromMap([
            'GOOGLE_APPLICATION_CREDENTIALS' => '  /secrets/sa.json  ',
            'DOCS_ROOT_FOLDER_ID' => self::ROOT_ID,
            'DOCS_WRITE_ENABLED' => 'true',
            'DOCS_APPEND_ALLOWED_IDS' => '1aBcDeFgHiJ, 2KlMnOpQrSt, 1aBcDeFgHiJ, ,',
            'DOCS_MAX_READ_CHARS' => '1500',
            'DOCS_MAX_APPEND_CHARS' => '40',
            'MCP_SESSION_DIR' => '/tmp/docs-sessions',
            'TZ' => 'Europe/Moscow',
        ]);

        self::assertSame('/secrets/sa.json', $config->googleApplicationCredentials);
        self::assertSame(self::ROOT_ID, $config->rootFolderId);
        self::assertTrue($config->writeEnabled);
        self::assertSame(['1aBcDeFgHiJ', '2KlMnOpQrSt'], $config->appendAllowedIds);
        self::assertSame(1500, $config->maxReadChars);
        self::assertSame(40, $config->maxAppendChars);
        self::assertSame('/tmp/docs-sessions', $config->sessionDir);
        self::assertSame('Europe/Moscow', $config->timezone);
    }

    public function testDefaultsWhenOptionalValuesAreMissing(): void
    {
        $config = DocsConfig::fromMap([
            'GOOGLE_APPLICATION_CREDENTIALS' => '/secrets/sa.json',
            'DOCS_ROOT_FOLDER_ID' => self::ROOT_ID,
        ]);

        self::assertFalse($config->writeEnabled);
        self::assertSame([], $config->appendAllowedIds);
        self::assertSame(20000, $config->maxReadChars);
        self::assertSame(5000, $config->maxAppendChars);
        self::assertSame('/var/mcp-sessions', $config->sessionDir);
        self::assertSame('UTC', $config->timezone);
    }

    public function testEmptyOptionalValuesUseDefaults(): void
    {
        $config = DocsConfig::fromMap([
            'GOOGLE_APPLICATION_CREDENTIALS' => '/secrets/sa.json',
            'DOCS_ROOT_FOLDER_ID' => self::ROOT_ID,
            'DOCS_WRITE_ENABLED' => '',
            'DOCS_APPEND_ALLOWED_IDS' => ' , ',
            'DOCS_MAX_READ_CHARS' => '',
            'DOCS_MAX_APPEND_CHARS' => null,
            'MCP_SESSION_DIR' => '   ',
            'TZ' => '',
        ]);

        self::assertFalse($config->writeEnabled);
        self::assertSame([], $config->appendAllowedIds);
        self::assertSame(20000, $config->maxReadChars);
        self::assertSame(5000, $config->maxAppendChars);
        self::assertSame('/var/mcp-sessions', $config->sessionDir);
        self::assertSame('UTC', $config->timezone);
    }

    #[DataProvider('falseTokens')]
    public function testWriteEnabledFalseTokens(string $token): void
    {
        $config = $this->validMap(['DOCS_WRITE_ENABLED' => $token]);

        self::assertFalse($config->writeEnabled);
    }

    #[DataProvider('trueTokens')]
    public function testWriteEnabledTrueTokens(string $token): void
    {
        $config = $this->validMap(['DOCS_WRITE_ENABLED' => $token]);

        self::assertTrue($config->writeEnabled);
    }

    public function testInvalidConfigurationReportsRulesWithoutSecretValues(): void
    {
        try {
            DocsConfig::fromMap([
                'GOOGLE_APPLICATION_CREDENTIALS' => '   ',
                'DOCS_ROOT_FOLDER_ID' => self::SECRET,
                'DOCS_WRITE_ENABLED' => 'maybe-' . self::SECRET,
                'DOCS_APPEND_ALLOWED_IDS' => self::SECRET,
                'DOCS_MAX_READ_CHARS' => '0',
                'DOCS_MAX_APPEND_CHARS' => 'nope',
                'TZ' => 'Not/AZone',
            ]);
            self::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            self::assertSame(
                'Invalid configuration: GOOGLE_APPLICATION_CREDENTIALS must be set; '
                . 'DOCS_ROOT_FOLDER_ID must match ^[A-Za-z0-9_-]{10,}$; '
                . 'DOCS_WRITE_ENABLED must be a boolean; '
                . 'DOCS_APPEND_ALLOWED_IDS contains an invalid id; '
                . 'DOCS_MAX_READ_CHARS must be a positive integer; '
                . 'DOCS_MAX_APPEND_CHARS must be a positive integer; '
                . 'TZ must be a valid timezone identifier',
                $e->getMessage(),
            );
            self::assertStringNotContainsString(self::SECRET, $e->getMessage());
            self::assertStringNotContainsString('private_key', $e->getMessage());
        }
    }

    public function testFromEnvironmentReadsProcessEnvironment(): void
    {
        $keys = [
            'GOOGLE_APPLICATION_CREDENTIALS',
            'DOCS_ROOT_FOLDER_ID',
            'DOCS_WRITE_ENABLED',
            'DOCS_APPEND_ALLOWED_IDS',
            'DOCS_MAX_READ_CHARS',
            'DOCS_MAX_APPEND_CHARS',
            'MCP_SESSION_DIR',
            'TZ',
        ];
        $previous = [];
        foreach ($keys as $key) {
            $current = getenv($key);
            $previous[$key] = is_string($current) ? $current : null;
        }

        try {
            $this->setProcessEnv('GOOGLE_APPLICATION_CREDENTIALS', '/secrets/from-env.json');
            $this->setProcessEnv('DOCS_ROOT_FOLDER_ID', self::ROOT_ID);
            $this->setProcessEnv('DOCS_WRITE_ENABLED', 'false');
            $this->setProcessEnv('DOCS_APPEND_ALLOWED_IDS', '');
            $this->setProcessEnv('DOCS_MAX_READ_CHARS', '');
            $this->setProcessEnv('DOCS_MAX_APPEND_CHARS', '');
            $this->setProcessEnv('MCP_SESSION_DIR', '');
            $this->setProcessEnv('TZ', '');

            $config = DocsConfig::fromEnvironment();

            self::assertSame('/secrets/from-env.json', $config->googleApplicationCredentials);
            self::assertSame(self::ROOT_ID, $config->rootFolderId);
            self::assertFalse($config->writeEnabled);
            self::assertSame(20000, $config->maxReadChars);
            self::assertSame('UTC', $config->timezone);
        } finally {
            foreach ($previous as $key => $value) {
                $this->setProcessEnv($key, $value);
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function falseTokens(): iterable
    {
        yield 'zero' => ['0'];
        yield 'false' => ['false'];
        yield 'no' => ['no'];
        yield 'off' => ['off'];
        yield 'uppercase' => ['FALSE'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function trueTokens(): iterable
    {
        yield 'one' => ['1'];
        yield 'true' => ['true'];
        yield 'yes' => ['yes'];
        yield 'on' => ['on'];
        yield 'uppercase' => ['TRUE'];
    }

    /**
     * @param array<string, string|null> $overrides
     */
    private function validMap(array $overrides): DocsConfig
    {
        return DocsConfig::fromMap(array_merge([
            'GOOGLE_APPLICATION_CREDENTIALS' => '/secrets/sa.json',
            'DOCS_ROOT_FOLDER_ID' => self::ROOT_ID,
        ], $overrides));
    }

    private function setProcessEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key]);

            return;
        }

        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
    }
}

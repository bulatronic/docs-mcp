<?php

declare(strict_types=1);

namespace DocsMcp\Infrastructure\Config;

use DocsMcp\Domain\ResourceId;
use InvalidArgumentException;

final readonly class DocsConfig
{
    private const int DEFAULT_MAX_READ_CHARS = 20000;

    private const int DEFAULT_MAX_APPEND_CHARS = 5000;

    private const string DEFAULT_SESSION_DIR = '/var/mcp-sessions';

    private const string DEFAULT_TIMEZONE = 'UTC';

    /**
     * @param list<string> $appendAllowedIds
     */
    public function __construct(
        #[\SensitiveParameter]
        public string $googleApplicationCredentials,
        public string $rootFolderId,
        public bool $writeEnabled,
        public array $appendAllowedIds,
        public int $maxReadChars,
        public int $maxAppendChars,
        public string $sessionDir,
        public string $timezone,
    ) {}

    public static function fromEnvironment(): self
    {
        return self::fromMap(self::environmentMap());
    }

    /**
     * @param array<string, string|null> $env
     */
    public static function fromMap(
        #[\SensitiveParameter]
        array $env,
    ): self {
        $errors = [];

        $credentials = self::optionalString($env, 'GOOGLE_APPLICATION_CREDENTIALS');
        if ($credentials === null || $credentials === '') {
            $errors[] = 'GOOGLE_APPLICATION_CREDENTIALS must be set';
            $credentials = '';
        }

        $rootFolderId = self::optionalString($env, 'DOCS_ROOT_FOLDER_ID') ?? '';
        if (!ResourceId::isValid($rootFolderId)) {
            $errors[] = 'DOCS_ROOT_FOLDER_ID must match ' . self::patternSource();
            $rootFolderId = '';
        }

        try {
            $writeEnabled = self::boolValue($env, 'DOCS_WRITE_ENABLED', false);
        } catch (InvalidArgumentException) {
            $errors[] = 'DOCS_WRITE_ENABLED must be a boolean';
            $writeEnabled = false;
        }

        try {
            $appendAllowedIds = self::idList($env);
        } catch (InvalidArgumentException) {
            $errors[] = 'DOCS_APPEND_ALLOWED_IDS contains an invalid id';
            $appendAllowedIds = [];
        }

        try {
            $maxReadChars = self::positiveInt($env, 'DOCS_MAX_READ_CHARS', self::DEFAULT_MAX_READ_CHARS);
        } catch (InvalidArgumentException) {
            $errors[] = 'DOCS_MAX_READ_CHARS must be a positive integer';
            $maxReadChars = self::DEFAULT_MAX_READ_CHARS;
        }

        try {
            $maxAppendChars = self::positiveInt($env, 'DOCS_MAX_APPEND_CHARS', self::DEFAULT_MAX_APPEND_CHARS);
        } catch (InvalidArgumentException) {
            $errors[] = 'DOCS_MAX_APPEND_CHARS must be a positive integer';
            $maxAppendChars = self::DEFAULT_MAX_APPEND_CHARS;
        }

        $sessionDir = self::optionalString($env, 'MCP_SESSION_DIR');
        if ($sessionDir === null || $sessionDir === '') {
            $sessionDir = self::DEFAULT_SESSION_DIR;
        }

        $timezone = self::optionalString($env, 'TZ');
        if ($timezone === null || $timezone === '') {
            $timezone = self::DEFAULT_TIMEZONE;
        } elseif (!in_array($timezone, timezone_identifiers_list(), true)) {
            $errors[] = 'TZ must be a valid timezone identifier';
            $timezone = self::DEFAULT_TIMEZONE;
        }

        if ($errors !== []) {
            throw new InvalidArgumentException('Invalid configuration: ' . implode('; ', $errors));
        }

        return new self(
            googleApplicationCredentials: $credentials,
            rootFolderId: $rootFolderId,
            writeEnabled: $writeEnabled,
            appendAllowedIds: $appendAllowedIds,
            maxReadChars: $maxReadChars,
            maxAppendChars: $maxAppendChars,
            sessionDir: $sessionDir,
            timezone: $timezone,
        );
    }

    /**
     * @param array<string, string|null> $env
     */
    private static function optionalString(array $env, string $key): ?string
    {
        if (!array_key_exists($key, $env) || $env[$key] === null) {
            return null;
        }

        return trim($env[$key]);
    }

    /**
     * @param array<string, string|null> $env
     */
    private static function boolValue(array $env, string $key, bool $default): bool
    {
        $raw = self::optionalString($env, $key);
        if ($raw === null || $raw === '') {
            return $default;
        }

        $normalized = strtolower($raw);
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        throw new InvalidArgumentException($key);
    }

    /**
     * @param array<string, string|null> $env
     *
     * @return list<string>
     */
    private static function idList(array $env): array
    {
        $raw = self::optionalString($env, 'DOCS_APPEND_ALLOWED_IDS');
        if ($raw === null || $raw === '') {
            return [];
        }

        $ids = [];
        foreach (explode(',', $raw) as $part) {
            $id = trim($part);
            if ($id === '') {
                continue;
            }
            if (!ResourceId::isValid($id)) {
                throw new InvalidArgumentException('DOCS_APPEND_ALLOWED_IDS');
            }
            if (!in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param array<string, string|null> $env
     */
    private static function positiveInt(array $env, string $key, int $default): int
    {
        $raw = self::optionalString($env, $key);
        if ($raw === null || $raw === '') {
            return $default;
        }

        if (preg_match('/^[1-9][0-9]*$/', $raw) !== 1) {
            throw new InvalidArgumentException($key);
        }

        $value = filter_var($raw, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($value)) {
            throw new InvalidArgumentException($key);
        }

        return $value;
    }

    /**
     * @return array<string, string>
     */
    private static function environmentMap(): array
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

        $map = [];
        foreach ($keys as $key) {
            $value = self::readProcessEnv($key);
            if ($value !== null) {
                $map[$key] = $value;
            }
        }

        return $map;
    }

    private static function readProcessEnv(string $key): ?string
    {
        $value = getenv($key);
        if (is_string($value)) {
            return $value;
        }

        $fromSuperglobal = $_ENV[$key] ?? null;

        return is_string($fromSuperglobal) ? $fromSuperglobal : null;
    }

    private static function patternSource(): string
    {
        return trim(ResourceId::PATTERN, '/');
    }
}

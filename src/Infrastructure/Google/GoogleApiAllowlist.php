<?php

declare(strict_types=1);

namespace DocsMcp\Infrastructure\Google;

use DocsMcp\Domain\Drive\DriveId;
use InvalidArgumentException;

/**
 * Method + path allowlist for Google API calls. Rejects anything else before I/O.
 */
final class GoogleApiAllowlist
{
    public const string DRIVE_FILES = 'https://www.googleapis.com/drive/v3/files';

    public const string DOCS_DOCUMENTS = 'https://docs.googleapis.com/v1/documents';

    /** @var list<string> */
    public const array FILE_METADATA_FIELDS = [
        'id',
        'name',
        'mimeType',
        'parents',
        'trashed',
        'modifiedTime',
    ];

    private function __construct() {}

    /**
     * @param array<string, scalar|null> $query
     */
    public static function assertAllowed(string $method, string $url, array $query = []): void
    {
        $method = strtoupper($method);
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'], $parts['path'])) {
            throw new InvalidArgumentException('Google API URL is not allowlisted');
        }

        $origin = $parts['scheme'] . '://' . $parts['host'];
        $path = $parts['path'];

        if ($method === 'GET' && $origin . $path === self::DRIVE_FILES) {
            self::assertQueryHas($query, ['q', 'fields']);

            return;
        }

        if ($method === 'GET' && preg_match(
            '#^' . preg_quote(self::DRIVE_FILES, '#') . '/(' . self::idPattern() . ')$#',
            $origin . $path,
            $matches,
        ) === 1) {
            self::assertDriveId($matches[1]);
            self::assertFieldsSubset($query);

            return;
        }

        if ($method === 'GET' && preg_match(
            '#^' . preg_quote(self::DOCS_DOCUMENTS, '#') . '/(' . self::idPattern() . ')$#',
            $origin . $path,
            $matches,
        ) === 1) {
            self::assertDriveId($matches[1]);

            return;
        }

        if ($method === 'POST' && preg_match(
            '#^' . preg_quote(self::DOCS_DOCUMENTS, '#') . '/(' . self::idPattern() . '):batchUpdate$#',
            $origin . $path,
            $matches,
        ) === 1) {
            self::assertDriveId($matches[1]);

            return;
        }

        throw new InvalidArgumentException('Google API request is not allowlisted');
    }

    /**
     * @param list<string> $fields
     */
    public static function assertMetadataFields(array $fields): string
    {
        $allowed = self::FILE_METADATA_FIELDS;
        foreach ($fields as $field) {
            if (!in_array($field, $allowed, true)) {
                throw new InvalidArgumentException('Drive fields are not allowlisted');
            }
        }

        return implode(',', $fields);
    }

    /**
     * @param array<string, scalar|null> $query
     * @param list<string> $required
     */
    private static function assertQueryHas(array $query, array $required): void
    {
        foreach ($required as $key) {
            if (!array_key_exists($key, $query) || $query[$key] === null || $query[$key] === '') {
                throw new InvalidArgumentException('Google API request is not allowlisted');
            }
        }
    }

    /**
     * @param array<string, scalar|null> $query
     */
    private static function assertFieldsSubset(array $query): void
    {
        if (!isset($query['fields']) || !is_string($query['fields']) || $query['fields'] === '') {
            throw new InvalidArgumentException('Google API request is not allowlisted');
        }

        $fields = array_values(array_filter(array_map('trim', explode(',', $query['fields'])), static fn(string $f): bool => $f !== ''));
        self::assertMetadataFields($fields);
    }

    private static function assertDriveId(string $id): void
    {
        if (!DriveId::isValid($id)) {
            throw new InvalidArgumentException('Google API request is not allowlisted');
        }
    }

    private static function idPattern(): string
    {
        return '[A-Za-z0-9_-]{10,}';
    }
}

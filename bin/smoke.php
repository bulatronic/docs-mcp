#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Manual smoke check against a real Google service account.
 * CLI-only — not exposed by FrankenPHP (document root is public/).
 *
 * Usage:
 *   docker compose --profile tools run --rm --entrypoint php composer /app/bin/smoke.php
 *   ... --read=DOCUMENT_ID
 *   ... --append=DOCUMENT_ID   # requires DOCS_WRITE_ENABLED=true and ID in DOCS_APPEND_ALLOWED_IDS
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "bin/smoke.php is CLI-only\n");
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

use DocsMcp\Domain\Drive\DriveId;
use DocsMcp\Domain\Drive\DriveMimeType;
use DocsMcp\Infrastructure\Config\DocsConfig;
use DocsMcp\Infrastructure\Google\GoogleAccessTokenProvider;
use DocsMcp\Infrastructure\Google\GoogleDocumentAppender;
use DocsMcp\Infrastructure\Google\GoogleDocumentTextReader;
use DocsMcp\Infrastructure\Google\GoogleDriveMetadataReader;
use DocsMcp\Infrastructure\Google\GoogleHttpGateway;
use InvalidArgumentException;
use Symfony\Component\HttpClient\HttpClient;
use Throwable;

$readId = null;
$appendId = null;
$argvList = $_SERVER['argv'] ?? [];
if (!is_array($argvList)) {
    $argvList = [];
}
/** @var list<string> $args */
$args = [];
foreach (array_slice($argvList, 1) as $arg) {
    if (is_string($arg)) {
        $args[] = $arg;
    }
}
foreach ($args as $arg) {
    if (str_starts_with($arg, '--read=')) {
        $readId = substr($arg, 7);
    } elseif (str_starts_with($arg, '--append=')) {
        $appendId = substr($arg, 9);
    } elseif ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "Usage: smoke.php [--read=ID] [--append=ID]\n");
        exit(0);
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        exit(1);
    }
}

try {
    $config = DocsConfig::fromEnvironment();
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

if (!date_default_timezone_set($config->timezone)) {
    fwrite(STDERR, "Invalid TZ\n");
    exit(1);
}

$tokenProvider = new GoogleAccessTokenProvider(
    $config->googleApplicationCredentials,
    GoogleAccessTokenProvider::createFilesystemCache($config->sessionDir),
);
$gateway = new GoogleHttpGateway(HttpClient::create(), $tokenProvider);
$metadata = new GoogleDriveMetadataReader($gateway);
$textReader = new GoogleDocumentTextReader($gateway);
$appender = new GoogleDocumentAppender($gateway);

$rootId = new DriveId($config->rootFolderId);

try {
    $children = $metadata->listChildren($rootId, 50);
} catch (Throwable $e) {
    fwrite(STDERR, 'list failed: ' . $e::class . "\n");
    exit(1);
}

fwrite(STDOUT, sprintf("Root %s — %d item(s)\n", $rootId->value, count($children)));
foreach ($children as $item) {
    $kind = match ($item->mimeType) {
        DriveMimeType::DOCUMENT => 'doc',
        DriveMimeType::FOLDER => 'folder',
        DriveMimeType::SHORTCUT => 'shortcut',
        default => 'other',
    };
    fwrite(STDOUT, sprintf("  [%s] %s  %s\n", $kind, $item->id->value, $item->name));
}

if ($readId !== null) {
    if (!DriveId::isValid($readId)) {
        fwrite(STDERR, "Invalid --read id\n");
        exit(1);
    }
    try {
        $document = $textReader->read(new DriveId($readId));
    } catch (Throwable $e) {
        fwrite(STDERR, 'read failed: ' . $e::class . "\n");
        exit(1);
    }
    fwrite(STDOUT, sprintf(
        "Read %s (%s): %d chars\n",
        $document->id->value,
        $document->title,
        mb_strlen($document->text),
    ));
}

if ($appendId !== null) {
    if (!DriveId::isValid($appendId)) {
        fwrite(STDERR, "Invalid --append id\n");
        exit(1);
    }
    if (!$config->writeEnabled) {
        fwrite(STDERR, "Append refused: DOCS_WRITE_ENABLED is false\n");
        exit(1);
    }
    if (!in_array($appendId, $config->appendAllowedIds, true)) {
        fwrite(STDERR, "Append refused: id is not in DOCS_APPEND_ALLOWED_IDS\n");
        exit(1);
    }

    try {
        $appender->appendText(new DriveId($appendId), 'smoke test');
    } catch (Throwable $e) {
        fwrite(STDERR, 'append failed: ' . $e::class . "\n");
        exit(1);
    }
    fwrite(STDOUT, sprintf("Appended to %s\n", $appendId));
}

exit(0);

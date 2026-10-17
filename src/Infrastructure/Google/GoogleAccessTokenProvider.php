<?php

declare(strict_types=1);

namespace DocsMcp\Infrastructure\Google;

use Google\Auth\Credentials\ServiceAccountCredentials;
use Google\Auth\FetchAuthTokenInterface;
use Psr\Cache\CacheItemPoolInterface;
use RuntimeException;

/**
 * Loads a service-account access token and caches it in PSR-6 for multi-process PHP.
 * Never logs credentials or token values.
 */
final class GoogleAccessTokenProvider
{
    private const string CACHE_KEY = 'google_sa_access_token';

    private const array SCOPES = [
        'https://www.googleapis.com/auth/documents',
        'https://www.googleapis.com/auth/drive',
    ];

    private FetchAuthTokenInterface $credentials;

    public function __construct(
        #[\SensitiveParameter]
        string $credentialsPath,
        private CacheItemPoolInterface $cache,
        ?FetchAuthTokenInterface $credentials = null,
    ) {
        $this->credentials = $credentials ?? new ServiceAccountCredentials(
            self::SCOPES,
            $credentialsPath,
        );
    }

    public function getAccessToken(): string
    {
        $item = $this->cache->getItem(self::CACHE_KEY);
        if ($item->isHit()) {
            $cached = $item->get();
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        try {
            $auth = $this->credentials->fetchAuthToken();
        } catch (\Throwable $e) {
            throw new RuntimeException('Unable to obtain Google access token', 0, $e);
        }

        $token = $auth['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new RuntimeException('Google access token response was empty');
        }

        $expiresIn = $auth['expires_in'] ?? 3600;
        $ttl = is_int($expiresIn) || is_float($expiresIn) || (is_string($expiresIn) && is_numeric($expiresIn))
            ? (int) $expiresIn
            : 3600;
        // Refresh with a margin so workers do not reuse a near-expiry token.
        $item->set($token);
        $item->expiresAfter(max(60, $ttl - 60));
        $this->cache->save($item);

        return $token;
    }

    /**
     * Filesystem cache under MCP_SESSION_DIR with restrictive file mode.
     */
    public static function createFilesystemCache(string $sessionDir): CacheItemPoolInterface
    {
        $directory = rtrim($sessionDir, '/') . '/google-token-cache';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create Google token cache directory');
        }
        chmod($directory, 0700);

        $previous = umask(0077);
        try {
            return new \Symfony\Component\Cache\Adapter\FilesystemAdapter(
                namespace: 'docs_mcp_google',
                defaultLifetime: 0,
                directory: $directory,
            );
        } finally {
            umask($previous);
        }
    }
}

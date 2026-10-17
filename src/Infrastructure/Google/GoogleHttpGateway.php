<?php

declare(strict_types=1);

namespace DocsMcp\Infrastructure\Google;

use DocsMcp\Domain\Drive\DriveId;
use DocsMcp\Domain\Error\ItemNotFound;
use DocsMcp\Domain\Error\UpstreamRejected;
use DocsMcp\Domain\Error\UpstreamTemporary;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Single egress point to Google APIs. Only allowlisted operations can be expressed.
 */
final class GoogleHttpGateway
{
    private const int TIMEOUT_SECONDS = 15;

    private const int MAX_ATTEMPTS = 4;

    private const string METADATA_FIELDS = 'id,name,mimeType,parents,trashed,modifiedTime';

    public function __construct(
        private HttpClientInterface $httpClient,
        private GoogleAccessTokenProvider $tokenProvider,
        private Sleeper $sleeper = new UsleepSleeper(),
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getFileMetadata(DriveId $id): array
    {
        $url = GoogleApiAllowlist::DRIVE_FILES . '/' . $id->value;
        $query = ['fields' => self::METADATA_FIELDS];

        return $this->requestJson('GET', $url, $query);
    }

    /**
     * @return array<string, mixed>
     */
    public function listFiles(string $q, int $pageSize): array
    {
        $url = GoogleApiAllowlist::DRIVE_FILES;
        $query = [
            'q' => $q,
            'fields' => 'files(' . self::METADATA_FIELDS . ')',
            'pageSize' => max(1, $pageSize),
        ];

        return $this->requestJson('GET', $url, $query);
    }

    /**
     * @return array<string, mixed>
     */
    public function getDocument(DriveId $id): array
    {
        $url = GoogleApiAllowlist::DOCS_DOCUMENTS . '/' . $id->value;

        return $this->requestJson('GET', $url);
    }

    /**
     * Appends plain text at the end of the document body.
     * The only batchUpdate request this gateway can build is a single insertText
     * with endOfSegmentLocation {segmentId: ""}.
     */
    public function insertTextAtEnd(DriveId $documentId, string $text): void
    {
        $url = GoogleApiAllowlist::DOCS_DOCUMENTS . '/' . $documentId->value . ':batchUpdate';
        $body = [
            'requests' => [
                [
                    'insertText' => [
                        'text' => $text,
                        'endOfSegmentLocation' => [
                            'segmentId' => '',
                        ],
                    ],
                ],
            ],
        ];

        $this->requestJson('POST', $url, body: $body);
    }

    /**
     * @param array<string, scalar|null> $query
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function requestJson(
        string $method,
        string $url,
        array $query = [],
        ?array $body = null,
    ): array {
        GoogleApiAllowlist::assertAllowed($method, $url, $query);

        $attempt = 0;
        $lastRetryable = null;

        while ($attempt < self::MAX_ATTEMPTS) {
            ++$attempt;

            try {
                $response = $this->send($method, $url, $query, $body);
            } catch (TransportExceptionInterface $e) {
                $lastRetryable = $e;
                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw new UpstreamTemporary();
                }
                $this->sleeper->sleep($this->backoffSeconds($attempt));
                continue;
            }

            $status = $response->getStatusCode();
            $raw = $response->getContent(false);

            if ($status >= 200 && $status < 300) {
                if ($raw === '') {
                    return [];
                }

                try {
                    $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    throw new UpstreamRejected('Upstream returned an invalid JSON payload');
                }

                if (!is_array($decoded)) {
                    throw new UpstreamRejected('Upstream returned a non-object JSON payload');
                }

                /** @var array<string, mixed> $decoded */
                return $decoded;
            }

            if ($this->isRetryableStatus($status)) {
                $lastRetryable = new UpstreamTemporary();
                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw new UpstreamTemporary();
                }
                $this->sleeper->sleep($this->backoffSeconds($attempt));
                continue;
            }

            $this->throwMappedClientError($status, $raw);
        }

        throw $lastRetryable instanceof UpstreamTemporary
            ? $lastRetryable
            : new UpstreamTemporary();
    }

    /**
     * @param array<string, scalar|null> $query
     * @param array<string, mixed>|null $body
     */
    private function send(
        string $method,
        string $url,
        array $query,
        ?array $body,
    ): ResponseInterface {
        $token = $this->tokenProvider->getAccessToken();

        $options = [
            'timeout' => self::TIMEOUT_SECONDS,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ],
            'query' => $query,
        ];

        if ($body !== null) {
            $options['headers']['Content-Type'] = 'application/json';
            $options['json'] = $body;
        }

        return $this->httpClient->request($method, $url, $options);
    }

    private function throwMappedClientError(int $status, string $raw): never
    {
        if ($status === 403 && $this->isStorageQuotaExceeded($raw)) {
            throw new UpstreamRejected('Google Drive storage quota exceeded');
        }

        if ($status === 404 || $status === 403) {
            throw new ItemNotFound();
        }

        throw new UpstreamRejected();
    }

    private function isStorageQuotaExceeded(string $raw): bool
    {
        if ($raw === '') {
            return false;
        }

        try {
            $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        if (!is_array($decoded)) {
            return false;
        }

        $error = $decoded['error'] ?? null;
        if (!is_array($error)) {
            return false;
        }

        $errors = $error['errors'] ?? null;
        if (is_array($errors)) {
            foreach ($errors as $entry) {
                if (is_array($entry) && ($entry['reason'] ?? null) === 'storageQuotaExceeded') {
                    return true;
                }
            }
        }

        $message = $error['message'] ?? null;
        if (is_string($message) && str_contains($message, 'storageQuotaExceeded')) {
            return true;
        }

        $status = $error['status'] ?? null;

        return is_string($status) && str_contains($status, 'storageQuotaExceeded');
    }

    private function isRetryableStatus(int $status): bool
    {
        return $status === 429 || $status >= 500;
    }

    private function backoffSeconds(int $attemptJustFinished): float
    {
        // Attempts 1..3 that failed: sleep 1, 2, 4 seconds before the next try.
        return 2 ** ($attemptJustFinished - 1);
    }
}

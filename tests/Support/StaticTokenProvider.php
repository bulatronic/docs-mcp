<?php

declare(strict_types=1);

namespace DocsMcp\Tests\Support;

use DocsMcp\Infrastructure\Google\GoogleAccessTokenProvider;
use Google\Auth\FetchAuthTokenInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Builds a GoogleAccessTokenProvider that always returns a fixed token (no key file).
 */
final class StaticTokenProvider
{
    public static function create(string $token = 'test-access-token'): GoogleAccessTokenProvider
    {
        $credentials = new class ($token) implements FetchAuthTokenInterface {
            public function __construct(
                #[\SensitiveParameter]
                private string $token,
            ) {}

            public function fetchAuthToken(?callable $httpHandler = null)
            {
                return [
                    'access_token' => $this->token,
                    'expires_in' => 3600,
                ];
            }

            public function getCacheKey()
            {
                return 'static-test-token';
            }

            public function getLastReceivedToken()
            {
                return [
                    'access_token' => $this->token,
                    'expires_at' => time() + 3600,
                ];
            }
        };

        return new GoogleAccessTokenProvider(
            '/dev/null',
            new ArrayAdapter(),
            $credentials,
        );
    }
}

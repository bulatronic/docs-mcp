<?php

declare(strict_types=1);

namespace DocsMcp\Tests\Unit;

use DocsMcp\Infrastructure\Google\GoogleAccessTokenProvider;
use Google\Auth\FetchAuthTokenInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class GoogleAccessTokenProviderTest extends TestCase
{
    public function testCachesAccessTokenAcrossCalls(): void
    {
        $fetches = 0;
        $credentials = new class ($fetches) implements FetchAuthTokenInterface {
            public function __construct(private int &$fetches) {}

            public function fetchAuthToken(?callable $httpHandler = null)
            {
                ++$this->fetches;

                return [
                    'access_token' => 'cached-token-value',
                    'expires_in' => 3600,
                ];
            }

            public function getCacheKey()
            {
                return 'test-cache-key';
            }

            public function getLastReceivedToken()
            {
                return null;
            }
        };

        $provider = new GoogleAccessTokenProvider('/unused.json', new ArrayAdapter(), $credentials);

        self::assertSame('cached-token-value', $provider->getAccessToken());
        self::assertSame('cached-token-value', $provider->getAccessToken());
        self::assertSame(1, $fetches);
    }

    public function testFailureMessageDoesNotIncludeKeyOrTokenMaterial(): void
    {
        $credentials = new class implements FetchAuthTokenInterface {
            public function fetchAuthToken(?callable $httpHandler = null)
            {
                throw new RuntimeException('private_key=BEGIN PRIVATE KEY ya29.SECRET');
            }

            public function getCacheKey()
            {
                return 'fail-key';
            }

            public function getLastReceivedToken()
            {
                return null;
            }
        };

        $provider = new GoogleAccessTokenProvider('/secrets/sa.json', new ArrayAdapter(), $credentials);

        try {
            $provider->getAccessToken();
            self::fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            self::assertSame('Unable to obtain Google access token', $e->getMessage());
            self::assertStringNotContainsString('PRIVATE KEY', $e->getMessage());
            self::assertStringNotContainsString('ya29.SECRET', $e->getMessage());
            self::assertStringNotContainsString('/secrets/sa.json', $e->getMessage());
        }
    }
}

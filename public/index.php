<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use DocsMcp\Application\Policy\RootFolderPolicy;
use DocsMcp\Application\UseCase\GetDocumentContent;
use DocsMcp\Application\UseCase\ListDocuments;
use DocsMcp\Domain\Drive\DriveId;
use DocsMcp\Infrastructure\Config\DocsConfig;
use DocsMcp\Infrastructure\Google\GoogleAccessTokenProvider;
use DocsMcp\Infrastructure\Google\GoogleDocumentTextReader;
use DocsMcp\Infrastructure\Google\GoogleDriveMetadataReader;
use DocsMcp\Infrastructure\Google\GoogleHttpGateway;
use DocsMcp\Mcp\Audit\StdoutJsonAuditLogger;
use DocsMcp\Mcp\GuardedReferenceHandler;
use DocsMcp\Mcp\RateLimit\FileRateLimiter;
use DocsMcp\Ports\DocumentTextReader;
use DocsMcp\Ports\DriveMetadataReader;
use InvalidArgumentException;
use Mcp\Capability\Registry\Container;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Symfony\Component\HttpClient\HttpClient;

/*
 * Session strategy: FileSessionStore (MCP_SESSION_DIR).
 *
 * The SDK can answer modern-era (SEP-2575) requests without a session, and
 * StreamableHttpTransport routes those automatically. Handshake-era clients
 * (including MCP Inspector) still open an initialize session and send follow-up
 * requests with Mcp-Session-Id. PHP handles each HTTP request in a separate
 * process here, so InMemorySessionStore cannot share that state. A dedicated
 * StatelessHttpTransport / buildStateless() path is modern-era-only and would
 * break Inspector. FileSessionStore under MCP_SESSION_DIR is the fit.
 *
 * Classic FrankenPHP `php_server` ignores a returned PSR-7 Response (body stays
 * empty, Caddy defaults Content-Type to text/html). Emit status/headers/body
 * via the SAPI instead. (Worker mode + frankenphp_handle_request would do the
 * same conversion at the edge.)
 */

$psr17Factory = new Psr17Factory();
$requestCreator = new ServerRequestCreator(
    $psr17Factory,
    $psr17Factory,
    $psr17Factory,
    $psr17Factory,
);
$request = $requestCreator->fromGlobals();

if ($request->getUri()->getPath() !== '/mcp') {
    emitResponse(new Response(404, ['Content-Type' => 'text/plain; charset=utf-8'], 'Not Found'));
}

try {
    $config = DocsConfig::fromEnvironment();
} catch (InvalidArgumentException $e) {
    emitResponse(new Response(500, ['Content-Type' => 'text/plain; charset=utf-8'], $e->getMessage()));
}

if (!date_default_timezone_set($config->timezone)) {
    throw new RuntimeException('TZ must be a valid timezone identifier');
}

$sessionDir = $config->sessionDir;
if (!is_dir($sessionDir) && !mkdir($sessionDir, 0700, true) && !is_dir($sessionDir)) {
    throw new RuntimeException(sprintf('Unable to create MCP session directory: %s', $sessionDir));
}

$rootFolderId = new DriveId($config->rootFolderId);
$tokenProvider = new GoogleAccessTokenProvider(
    $config->googleApplicationCredentials,
    GoogleAccessTokenProvider::createFilesystemCache($sessionDir),
);
$gateway = new GoogleHttpGateway(HttpClient::create(), $tokenProvider);
$metadataReader = new GoogleDriveMetadataReader($gateway);
$textReader = new GoogleDocumentTextReader($gateway);
$policy = new RootFolderPolicy($metadataReader, $rootFolderId);
$listDocuments = new ListDocuments($metadataReader, $policy, $rootFolderId);
$getDocumentContent = new GetDocumentContent($textReader, $policy, $config->maxReadChars);

$container = new Container();
$container->set(DocsConfig::class, $config);
$container->set(DriveMetadataReader::class, $metadataReader);
$container->set(DocumentTextReader::class, $textReader);
$container->set(RootFolderPolicy::class, $policy);
$container->set(ListDocuments::class, $listDocuments);
$container->set(GetDocumentContent::class, $getDocumentContent);

$referenceHandler = new GuardedReferenceHandler(
    new ReferenceHandler($container),
    new FileRateLimiter($sessionDir . '/rate-limit.json', maxCalls: 60, windowSeconds: 60),
    new StdoutJsonAuditLogger(),
);

$server = Server::builder()
    ->setServerInfo('docs-mcp', '0.1.0')
    ->setContainer($container)
    ->setReferenceHandler($referenceHandler)
    ->setDiscovery(dirname(__DIR__), ['src'], ['vendor', 'tests'])
    ->setSession(new FileSessionStore($sessionDir))
    ->build();

// DnsRebindingProtectionMiddleware ignores ports; allow the hostnames used in
// compose (docs-mcp) and local inspector (localhost / 127.0.0.1), including :8080.
$transport = new StreamableHttpTransport(
    $request,
    middleware: [
        new DnsRebindingProtectionMiddleware(
            allowedHosts: ['docs-mcp', 'localhost', '127.0.0.1'],
        ),
    ],
);

emitResponse($server->run($transport));

function emitResponse(ResponseInterface $response): never
{
    http_response_code($response->getStatusCode());

    foreach ($response->getHeaders() as $name => $values) {
        foreach ($values as $value) {
            header(sprintf('%s: %s', $name, $value), false);
        }
    }

    echo $response->getBody();
    exit(0);
}

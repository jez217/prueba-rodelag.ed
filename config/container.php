<?php

declare(strict_types=1);

use App\Http\Middleware\JwtMiddleware;
use App\Http\Middleware\RequestLoggerMiddleware;
use App\Repository\AlertaRiesgoRepository;
use App\Services\GoApiClient;
use App\Services\AiServiceClient;
use GuzzleHttp\Client as GuzzleClient;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PDO;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

return [

    // ── Logger (JSON-line structured output) ──────────────────────────────────
    LoggerInterface::class => function (): LoggerInterface {
        $handler = new StreamHandler('php://stdout', $_ENV['LOG_LEVEL'] ?? 'info');
        $handler->setFormatter(new JsonFormatter());

        $logger = new Logger('php-api');
        $logger->pushHandler($handler);
        return $logger;
    },

    // ── PostgreSQL PDO connection ──────────────────────────────────────────────
    PDO::class => function (): PDO {
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $_ENV['DB_HOST'],
            $_ENV['DB_PORT'] ?? '5432',
            $_ENV['DB_NAME'],
        );

        $pdo = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASSWORD'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        return $pdo;
    },

    // ── HTTP client factory (Guzzle, reusable) ────────────────────────────────
    GuzzleClient::class => function (): GuzzleClient {
        return new GuzzleClient([
            'timeout'         => (float) ($_ENV['HTTP_TIMEOUT'] ?? 10),
            'connect_timeout' => 3.0,
            'http_errors'     => false, // we handle status codes ourselves
        ]);
    },

    // ── Upstream clients ──────────────────────────────────────────────────────
    GoApiClient::class => function (ContainerInterface $c): GoApiClient {
        return new GoApiClient(
            $c->get(GuzzleClient::class),
            $_ENV['GO_SERVICE_URL'],
            $c->get(LoggerInterface::class),
        );
    },

    AiServiceClient::class => function (ContainerInterface $c): AiServiceClient {
        return new AiServiceClient(
            $c->get(GuzzleClient::class),
            $_ENV['AI_SERVICE_URL'],
            (float) ($_ENV['LLM_TIMEOUT'] ?? 30),
            $c->get(LoggerInterface::class),
        );
    },

    // ── Repositories ──────────────────────────────────────────────────────────
    AlertaRiesgoRepository::class => function (ContainerInterface $c): AlertaRiesgoRepository {
        return new AlertaRiesgoRepository($c->get(PDO::class));
    },

    // ── Middleware ────────────────────────────────────────────────────────────
    JwtMiddleware::class => function (ContainerInterface $c): JwtMiddleware {
        return new JwtMiddleware(
            $_ENV['JWT_SECRET'],
            $c->get(LoggerInterface::class),
        );
    },

    RequestLoggerMiddleware::class => function (ContainerInterface $c): RequestLoggerMiddleware {
        return new RequestLoggerMiddleware($c->get(LoggerInterface::class));
    },
];

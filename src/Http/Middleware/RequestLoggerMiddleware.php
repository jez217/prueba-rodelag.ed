<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Emits a structured JSON log line for every request:
 *   { timestamp, endpoint, method, status, duration_ms, cliente_id }
 *
 * cliente_id is extracted from JWT claims if already set by JwtMiddleware.
 * Because Slim resolves middleware inside-out, RequestLogger wraps JwtMiddleware,
 * so claims will be present when we log the response.
 */
final class RequestLoggerMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function process(
        ServerRequestInterface  $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $start = hrtime(true); // nanoseconds

        $response = $handler->handle($request);

        $durationMs = (int) round((hrtime(true) - $start) / 1_000_000);

        $claims    = $request->getAttribute('jwt_claims', []);
        $clienteId = $claims['cliente_id'] ?? null;

        $this->logger->info('request', [
            'timestamp'   => (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339_EXTENDED),
            'method'      => $request->getMethod(),
            'endpoint'    => (string) $request->getUri()->getPath(),
            'status'      => $response->getStatusCode(),
            'duration_ms' => $durationMs,
            'cliente_id'  => $clienteId,
        ]);

        return $response;
    }
}

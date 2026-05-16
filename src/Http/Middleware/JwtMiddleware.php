<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

/**
 * Validates the HS256 JWT emitted by the Go service.
 * Uses the same JWT_SECRET — no network call required.
 *
 * On success, injects decoded claims into request attribute "jwt_claims"
 * so downstream handlers can read cliente_id and role without re-decoding.
 */
final class JwtMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly string          $secret,
        private readonly LoggerInterface $logger,
    ) {}

    public function process(
        ServerRequestInterface  $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $header = $request->getHeaderLine('Authorization');

        if ($header === '' || !str_starts_with($header, 'Bearer ')) {
            return $this->unauthorized('Missing or malformed Authorization header');
        }

        $token = substr($header, 7);

        try {
            $claims = JWT::decode($token, new Key($this->secret, 'HS256'));
        } catch (UnexpectedValueException $e) {
            $this->logger->warning('JWT validation failed', ['error' => $e->getMessage()]);
            return $this->unauthorized('Invalid or expired token');
        }

        // Attach decoded claims for downstream use
        $request = $request->withAttribute('jwt_claims', (array) $claims);

        return $handler->handle($request);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function unauthorized(string $message): ResponseInterface
    {
        // We build the response manually — no Slim dependency here
        $body = json_encode([
            'error'   => $message,
            'code'    => 'UNAUTHORIZED',
            'details' => new \stdClass(),
        ], JSON_UNESCAPED_UNICODE);

        // Lean on PHP's built-in response stream
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $body);
        rewind($stream);

        // Use Slim PSR-7 response through the global factory
        $response = new \Slim\Psr7\Response(401);
        $response->getBody()->write($body);
        return $response->withHeader('Content-Type', 'application/json');
    }
}

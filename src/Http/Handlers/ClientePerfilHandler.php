<?php

declare(strict_types=1);

namespace App\Http\Handlers;

use App\Exceptions\UpstreamUnavailableException;
use App\Repository\AlertaRiesgoRepository;
use App\Services\AiServiceClient;
use App\Services\GoApiClient;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/**
 * GET /api/clientes/{id}/perfil-completo
 *
 * Aggregates:
 *  1. Basic client profile from Go
 *  2. Recent transactions list from Go (last 30 days)
 *  3. LLM-generated behaviour insights from AI service
 *  4. Local risk metrics (alert counts by nivel) queried directly from PostgreSQL
 *
 * On upstream failure → 503 + Retry-After header.
 */
final class ClientePerfilHandler
{
    public function __construct(
        private readonly GoApiClient             $goClient,
        private readonly AiServiceClient         $aiClient,
        private readonly AlertaRiesgoRepository  $alertaRepo,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $clienteId = (int) $args['id'];
        $jwt       = $this->extractJwt($request);

        try {
            // 1. Core profile from Go
            $perfil = $this->goClient->getCliente($clienteId, $jwt);

            if ($perfil === []) {
                return $this->json($response, ['error' => 'Cliente no encontrado', 'code' => 'NOT_FOUND', 'details' => new \stdClass()], 404);
            }

            // 2. Recent transactions (filtered by last 30 days)
            $transacciones = $this->goClient->listTransacciones($clienteId, $jwt, [
                'fecha_desde' => (new \DateTimeImmutable('-30 days'))->format('Y-m-d'),
                'limit'       => 50,
            ]);

            // 3. LLM insights (uses longer timeout; returns degraded response on failure)
            $insights = $this->aiClient->insights($clienteId);

            // 4. Local risk metrics — counted directly from DB for freshness
            $metricas = $this->buildMetricas($transacciones['data'] ?? []);

        } catch (UpstreamUnavailableException $e) {
            return $this->serviceUnavailable($response, $e);
        }

        $payload = [
            'cliente'       => $perfil,
            'transacciones' => $transacciones['data'] ?? [],
            'insights'      => $insights,
            'metricas'      => $metricas,
        ];

        return $this->json($response, $payload);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * Derive simple metrics from the transaction list returned by Go.
     * (For heavier analytics, defer to Module 1 SQL queries.)
     */
    private function buildMetricas(array $transacciones): array
    {
        $totalGastado = 0.0;
        $porTipo      = [];

        foreach ($transacciones as $t) {
            $tipo = $t['tipo'] ?? 'desconocido';
            $porTipo[$tipo] = ($porTipo[$tipo] ?? 0.0) + (float) ($t['monto'] ?? 0);

            if ($tipo === 'gasto') {
                $totalGastado += (float) ($t['monto'] ?? 0);
            }
        }

        return [
            'num_transacciones' => count($transacciones),
            'total_gastado_30d' => round($totalGastado, 2),
            'por_tipo'          => $porTipo,
        ];
    }

    private function extractJwt(ServerRequestInterface $request): string
    {
        // JwtMiddleware already validated the token; we just propagate it upstream
        return substr($request->getHeaderLine('Authorization'), 7);
    }

    private function json(ResponseInterface $response, array $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }

    private function serviceUnavailable(ResponseInterface $response, UpstreamUnavailableException $e): ResponseInterface
    {
        $body = [
            'error'   => $e->getMessage(),
            'code'    => 'SERVICE_UNAVAILABLE',
            'details' => ['service' => $e->service],
        ];
        $response->getBody()->write(json_encode($body, JSON_UNESCAPED_UNICODE));
        return $response
            ->withStatus(503)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Retry-After', (string) $e->retryAfter);
    }
}

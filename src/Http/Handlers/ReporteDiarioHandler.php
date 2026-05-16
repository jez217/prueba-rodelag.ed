<?php

declare(strict_types=1);

namespace App\Http\Handlers;

use App\Exceptions\UpstreamUnavailableException;
use App\Services\GoApiClient;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/reportes/diario
 *
 * Builds an aggregated report for today by querying the Go microservice.
 * Admins see all transactions; a cliente role sees only their own.
 *
 * Returns summary statistics: total transactions, total volume, breakdown
 * by tipo, and a list of the highest-risk transactions (score > 0.7).
 */
final class ReporteDiarioHandler
{
    public function __construct(private readonly GoApiClient $goClient) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $claims    = $request->getAttribute('jwt_claims', []);
        $role      = $claims['role']      ?? 'cliente';
        $clienteId = (int) ($claims['cliente_id'] ?? 0);
        $jwt       = substr($request->getHeaderLine('Authorization'), 7);

        $today = (new \DateTimeImmutable())->format('Y-m-d');

        try {
            // Admins can query all; clientes are scoped to their own ID
            $queryParams = ['fecha_desde' => $today, 'fecha_hasta' => $today, 'limit' => 500];

            if ($role !== 'admin') {
                $queryParams['cliente_id'] = $clienteId;
            }

            $result = $this->goClient->listTransacciones(
                clienteId: $role === 'admin' ? 0 : $clienteId,
                jwt:       $jwt,
                query:     $queryParams,
            );
        } catch (UpstreamUnavailableException $e) {
            return $this->serviceUnavailable($response, $e);
        }

        $transacciones = $result['data'] ?? [];

        // ── Aggregate locally ──────────────────────────────────────────────
        $totalVolumen = 0.0;
        $porTipo      = [];
        $altaRiesgo   = [];

        foreach ($transacciones as $t) {
            $monto = (float) ($t['monto'] ?? 0);
            $tipo  = $t['tipo'] ?? 'desconocido';

            $totalVolumen        += $monto;
            $porTipo[$tipo]       = ($porTipo[$tipo] ?? 0.0) + $monto;

            // Flag transactions that already have an associated high-risk alert
            $alerta = $t['alerta_riesgo'] ?? null;
            if ($alerta && (float) ($alerta['score'] ?? 0) > 0.7) {
                $altaRiesgo[] = [
                    'transaccion_id' => $t['id'],
                    'monto'          => $monto,
                    'tipo'           => $tipo,
                    'score'          => $alerta['score'],
                    'nivel'          => $alerta['nivel'],
                ];
            }
        }

        usort($altaRiesgo, fn($a, $b) => $b['score'] <=> $a['score']);

        $payload = [
            'fecha'             => $today,
            'total_transaccion' => count($transacciones),
            'volumen_total'     => round($totalVolumen, 2),
            'por_tipo'          => $porTipo,
            'alertas_alto_riesgo' => array_slice($altaRiesgo, 0, 20),
        ];

        return $this->json($response, $payload);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

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

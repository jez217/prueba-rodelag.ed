<?php

declare(strict_types=1);

namespace App\Http\Handlers;

use App\Exceptions\UpstreamUnavailableException;
use App\Repository\AlertaRiesgoRepository;
use App\Services\AiServiceClient;
use App\Services\GoApiClient;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/analisis/transaccion
 *
 * Orchestration flow (spec §2.2, step 2.3-b):
 *  1. Receive transaction payload from the Go service (propagated JWT).
 *  2. Optionally fetch recent client history from Go for richer AI context.
 *  3. Call POST /ai/score-riesgo on the AI service.
 *  4. Persist the result in alertas_riesgo (PostgreSQL, Module 1 schema).
 *  5. Return { score, nivel } to Go.
 *
 * On any upstream failure → 503 + Retry-After.
 * Input validation is intentionally strict: missing fields → 400.
 */
final class AnalisisHandler
{
    public function __construct(
        private readonly GoApiClient            $goClient,
        private readonly AiServiceClient        $aiClient,
        private readonly AlertaRiesgoRepository $alertaRepo,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();

        // ── 1. Validate required fields ────────────────────────────────────
        $required = ['transaccion_id', 'cuenta_id', 'cliente_id', 'monto', 'tipo', 'divisa'];
        $missing  = array_diff($required, array_keys($body));

        if ($missing !== []) {
            return $this->json($response, [
                'error'   => 'Payload incompleto',
                'code'    => 'VALIDATION_ERROR',
                'details' => ['missing' => array_values($missing)],
            ], 400);
        }

        $transaccionId = (int) $body['transaccion_id'];
        $clienteId     = (int) $body['cliente_id'];
        $jwt           = substr($request->getHeaderLine('Authorization'), 7);

        try {
            // ── 2. Fetch recent history for richer AI scoring ──────────────
            $historial = $this->goClient->listTransacciones($clienteId, $jwt, [
                'fecha_desde' => (new \DateTimeImmutable('-90 days'))->format('Y-m-d'),
                'limit'       => 100,
            ]);

            // ── 3. Call AI service ─────────────────────────────────────────
            $resultado = $this->aiClient->scoreRiesgo(
                transaccion: $body,
                historial:   $historial['data'] ?? [],
            );

        } catch (UpstreamUnavailableException $e) {
            return $this->serviceUnavailable($response, $e);
        }

        // ── 4. Persist result in alertas_riesgo ────────────────────────────
        $score   = (float) ($resultado['score']   ?? 0.0);
        $nivel   = (string) ($resultado['nivel']  ?? 'bajo');
        $razones = (array) ($resultado['razones'] ?? []);

        $this->alertaRepo->upsert($transaccionId, $score, $nivel, $razones);

        // ── 5. Return score + nivel to the Go caller ───────────────────────
        return $this->json($response, [
            'transaccion_id' => $transaccionId,
            'score'          => $score,
            'nivel'          => $nivel,
            'razones'        => $razones,
        ], 200);
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

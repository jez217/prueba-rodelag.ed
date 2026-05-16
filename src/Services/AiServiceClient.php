<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\UpstreamUnavailableException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

/**
 * Client for the Python AI microservice (Module 3).
 *
 * POST /ai/score-riesgo  → fraud risk score
 * POST /ai/categorizar   → semantic categorisation
 * GET  /ai/insights/:id  → LLM-generated behaviour summary
 *
 * LLM calls can be slow; a separate $llmTimeout (default 30 s) is used
 * for insights. If the LLM times out, a degraded response is returned
 * so the caller is never left hanging.
 */
final class AiServiceClient
{
    public function __construct(
        private readonly Client          $http,
        private readonly string          $baseUrl,
        private readonly float           $llmTimeout,
        private readonly LoggerInterface $logger,
    ) {}

    // ── Public API ─────────────────────────────────────────────────────────

    /**
     * Score de riesgo de fraude para una transacción.
     *
     * @param  array $transaccion  Raw transaction payload
     * @param  array $historial    Recent transactions for the client
     * @return array{ score: float, nivel: string, razones: string[] }
     */
    public function scoreRiesgo(array $transaccion, array $historial = []): array
    {
        return $this->post('/ai/score-riesgo', [
            'transaccion' => $transaccion,
            'historial'   => $historial,
        ]);
    }

    /**
     * Categoriza descripción libre mediante embeddings + similitud coseno.
     */
    public function categorizar(string $descripcion): array
    {
        return $this->post('/ai/categorizar', ['descripcion' => $descripcion]);
    }

    /**
     * LLM-generated behaviour insights for a client.
     * Uses the longer llmTimeout and returns a degraded fallback on timeout.
     */
    public function insights(int $clienteId): array
    {
        $url = rtrim($this->baseUrl, '/') . "/ai/insights/{$clienteId}";

        try {
            $response = $this->http->get($url, [
                'timeout' => $this->llmTimeout,
                'headers' => ['Accept' => 'application/json'],
            ]);

            return json_decode((string) $response->getBody(), true) ?? [];

        } catch (ConnectException) {
            throw new UpstreamUnavailableException('AI service', 60);
        } catch (RequestException $e) {
            // Timeout or 5xx from LLM → return degraded response, don't fail the whole request
            $this->logger->warning('AI insights timeout/error — returning degraded response', [
                'cliente_id' => $clienteId,
                'error'      => $e->getMessage(),
            ]);

            return [
                'cliente_id'   => $clienteId,
                'insights'     => 'Análisis no disponible temporalmente.',
                'modelo_usado' => 'fallback',
                'tokens'       => 0,
                'degraded'     => true,
            ];
        }
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function post(string $path, array $body): array
    {
        $url = rtrim($this->baseUrl, '/') . $path;

        try {
            $response = $this->http->post($url, [
                'json'    => $body,
                'headers' => ['Accept' => 'application/json'],
            ]);
        } catch (ConnectException $e) {
            $this->logger->error('AI service unreachable', ['url' => $url, 'error' => $e->getMessage()]);
            throw new UpstreamUnavailableException('AI service', 60);
        } catch (RequestException $e) {
            $status = $e->getResponse()?->getStatusCode();
            if ($status !== null && $status >= 500) {
                throw new UpstreamUnavailableException('AI service', 60);
            }
            throw $e;
        }

        if ($response->getStatusCode() >= 500) {
            throw new UpstreamUnavailableException('AI service', 60);
        }

        return json_decode((string) $response->getBody(), true) ?? [];
    }
}

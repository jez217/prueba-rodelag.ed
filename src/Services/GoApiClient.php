<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\UpstreamUnavailableException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\TransferException;
use Psr\Log\LoggerInterface;

/**
 * Thin wrapper around the Go microservice.
 *
 * Propagates the caller's JWT so Go can authorise the request.
 * On connection failure or 5xx, throws UpstreamUnavailableException
 * which is caught at handler level and converted to 503 + Retry-After.
 */
final class GoApiClient
{
    public function __construct(
        private readonly Client          $http,
        private readonly string          $baseUrl,
        private readonly LoggerInterface $logger,
    ) {}

    // ── Public API ─────────────────────────────────────────────────────────

    /**
     * GET /transacciones/:id  (includes associated alertas_riesgo)
     */
    public function getTransaccion(int $id, string $jwt): array
    {
        return $this->get("/transacciones/{$id}", $jwt);
    }

    /**
     * GET /clientes/:id  (basic profile from Go)
     */
    public function getCliente(int $id, string $jwt): array
    {
        return $this->get("/clientes/{$id}", $jwt);
    }

    /**
     * GET /transacciones with filters (paginated)
     */
    public function listTransacciones(int $clienteId, string $jwt, array $query = []): array
    {
        $query['cliente_id'] = $clienteId;
        return $this->get('/transacciones?' . http_build_query($query), $jwt);
    }

    // ── Internal helpers ───────────────────────────────────────────────────

    private function get(string $path, string $jwt): array
    {
        return $this->request('GET', $path, $jwt);
    }

    private function post(string $path, string $jwt, array $body = []): array
    {
        return $this->request('POST', $path, $jwt, $body);
    }

    private function request(string $method, string $path, string $jwt, array $body = []): array
    {
        $url = rtrim($this->baseUrl, '/') . $path;

        $options = [
            'headers' => [
                'Authorization' => "Bearer {$jwt}",
                'Accept'        => 'application/json',
            ],
        ];

        if ($body !== []) {
            $options['json'] = $body;
        }

        try {
            $response = $this->http->request($method, $url, $options);
        } catch (ConnectException $e) {
            $this->logger->error('Go service unreachable', ['url' => $url, 'error' => $e->getMessage()]);
            throw new UpstreamUnavailableException('Go service', 30);
        } catch (TransferException $e) {
            $this->logger->error('Go service transfer error', ['url' => $url, 'error' => $e->getMessage()]);
            throw new UpstreamUnavailableException('Go service', 30);
        }

        $status = $response->getStatusCode();

        if ($status >= 500) {
            $this->logger->error('Go service returned 5xx', ['url' => $url, 'status' => $status]);
            throw new UpstreamUnavailableException('Go service', 30);
        }

        if ($status === 404) {
            return []; // caller decides how to handle not-found
        }

        return json_decode((string) $response->getBody(), true) ?? [];
    }
}

<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an upstream service (Go or AI) is unreachable or returns 5xx.
 * Carries a $retryAfter value (seconds) for the HTTP Retry-After header.
 */
final class UpstreamUnavailableException extends RuntimeException
{
    public function __construct(
        public readonly string $service,
        public readonly int    $retryAfter = 30,
    ) {
        parent::__construct("Upstream service '{$service}' is currently unavailable.");
    }
}

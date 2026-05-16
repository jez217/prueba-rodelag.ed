<?php

declare(strict_types=1);

use App\Http\Handlers\AnalisisHandler;
use App\Http\Handlers\ClientePerfilHandler;
use App\Http\Handlers\ReporteDiarioHandler;
use App\Http\Middleware\JwtMiddleware;
use App\Http\Middleware\RequestLoggerMiddleware;
use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

// ── Load environment variables ─────────────────────────────────────────────
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad(); // does not throw if .env is absent (Docker uses real env vars)

// ── Build DI container ─────────────────────────────────────────────────────
$builder = new ContainerBuilder();
$builder->addDefinitions(__DIR__ . '/../config/container.php');
$container = $builder->build();

// ── Create Slim app ────────────────────────────────────────────────────────
AppFactory::setContainer($container);
$app = AppFactory::create();

// ── Global middleware (outermost = last executed) ──────────────────────────
$app->addRoutingMiddleware();
$app->addBodyParsingMiddleware();
$app->add(RequestLoggerMiddleware::class);   // logs every request as JSON-line

// ── Error middleware ───────────────────────────────────────────────────────
$app->addErrorMiddleware(
    displayErrorDetails: ($_ENV['APP_ENV'] ?? 'production') === 'development',
    logErrors: true,
    logErrorDetails: true,
);

// ── Routes ─────────────────────────────────────────────────────────────────
// All routes require a valid JWT (validated locally — no Go round-trip)
$app->group('', function ($group) {

    // 2.2-a  Full client profile: Go data + local metrics
    $group->get('/api/clientes/{id}/perfil-completo', ClientePerfilHandler::class);

    // 2.2-b  Risk analysis: call AI service → persist in alertas_riesgo
    $group->post('/api/analisis/transaccion', AnalisisHandler::class);

    // 2.2-c  Daily aggregated report
    $group->get('/api/reportes/diario', ReporteDiarioHandler::class);

})->add(JwtMiddleware::class);

$app->run();

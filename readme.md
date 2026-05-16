# Módulo 2.2 — API PHP (Slim 4)

Capa de orquestación y enriquecimiento que consume el microservicio Go (2.1) y el servicio IA (3.1).

---

## Estructura

```
php-api/
├── public/index.php          # Entry point (Slim front controller)
├── src/
│   ├── Http/
│   │   ├── Handlers/         # Un handler por endpoint
│   │   └── Middleware/       # JwtMiddleware, RequestLoggerMiddleware
│   ├── Services/             # GoApiClient, AiServiceClient
│   ├── Repository/           # AlertaRiesgoRepository
│   └── Exceptions/           # UpstreamUnavailableException
├── config/container.php      # PHP-DI wiring
├── nginx/default.conf
├── Dockerfile                # Multi-stage (deps + runtime)
├── .env.example
└── docker-compose.yml        # Fragmento para integrar al compose raíz
```

---

## Endpoints

| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `/api/clientes/:id/perfil-completo` | Perfil Go + historial 30d + insights LLM + métricas locales |
| POST | `/api/analisis/transaccion` | Llama AI, persiste en `alertas_riesgo`, devuelve score a Go |
| GET | `/api/reportes/diario` | Reporte agregado del día vía endpoints Go |

Todos los endpoints requieren `Authorization: Bearer <JWT>` emitido por Go.

---

## Decisiones de diseño

**¿Por qué Slim 4 y no PHP puro?**  
Slim aporta routing, PSR-15 middleware y PSR-11 DI sin generar código mágico. Todo lo que hay en `src/` es código propio; Slim sólo conecta las piezas.

**Validación JWT local**  
`JwtMiddleware` decodifica el token con `firebase/php-jwt` usando la misma `JWT_SECRET` que Go. No hay round-trip de red; la validación es O(1) en tiempo constante.

**Fallo en cascada → 503 + Retry-After**  
`UpstreamUnavailableException` captura errores de conexión y 5xx tanto del servicio Go como del servicio IA. El handler lo convierte en `503` con el header `Retry-After` apropiado. El cliente puede reintentar sin lógica propia de backoff inicial.

**Fallback degradado para LLM**  
`AiServiceClient::insights()` devuelve `{ degraded: true, insights: "..." }` si el LLM supera el timeout de 30 s, en lugar de propagar el error al usuario. El campo `degraded` permite que el frontend lo indique visualmente.

**Logs estructurados**  
`RequestLoggerMiddleware` emite una línea JSON por request con `timestamp`, `method`, `endpoint`, `status`, `duration_ms` y `cliente_id`. Monolog escribe a `stdout`; Docker/K8s recoge y centraliza.

**Upsert en alertas_riesgo**  
`INSERT … ON CONFLICT DO UPDATE` garantiza idempotencia: reprocesar la misma transacción no viola la constraint `UNIQUE(transaccion_id)`.

---

## Cómo correr

```bash
# 1. Copiar variables de entorno
cp php-api/.env.example php-api/.env
# Editar JWT_SECRET, DB_PASSWORD, etc.

# 2. Levantar stack completo
docker-compose up --build

# 3. Probar un endpoint
curl -H "Authorization: Bearer <JWT>" \
     http://localhost:8081/api/clientes/1/perfil-completo
```

---

## Qué haría diferente con más tiempo

- **Tests de integración**: montar un PostgreSQL en memoria (testcontainers-go equivalente para PHP) y probar el flujo completo de `POST /api/analisis/transaccion`.
- **Circuit breaker**: implementar el patrón con un contador de fallos para dejar de llamar a un upstream caído en lugar de intentar cada request.
- **Cache de perfiles**: Redis TTL de 60 s en `GET /api/clientes/:id/perfil-completo` para reducir la carga sobre el servicio Go.
- **OpenAPI spec**: generar documentación desde atributos PHP 8 en los handlers.
- **Rate limiting**: actualmente está en el Go; podría reforzarse también en nginx con `limit_req_zone`.

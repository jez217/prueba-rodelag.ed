# ── Stage 1: Composer dependencies ────────────────────────────────────────────
FROM composer:2.7 AS deps

WORKDIR /app
COPY composer.json composer.lock* ./

# Install production deps only (no devDependencies in the final image)
RUN composer install \
    --no-dev \
    --no-scripts \
    --prefer-dist \
    --optimize-autoloader

# ── Stage 2: Runtime image ─────────────────────────────────────────────────────
FROM php:8.2-fpm-alpine

# System deps for PostgreSQL PDO
RUN apk add --no-cache \
    libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql

# Create non-root user for security
RUN addgroup -S appgroup && adduser -S appuser -G appgroup

WORKDIR /var/www/html

# Copy vendor from deps stage (avoids composer in runtime image)
COPY --from=deps /app/vendor ./vendor

# Copy application source
COPY src/        ./src/
COPY config/     ./config/
COPY public/     ./public/
COPY .env.example ./.env.example

# Adjust permissions
RUN chown -R appuser:appgroup /var/www/html

USER appuser

EXPOSE 9000

CMD ["php-fpm"]

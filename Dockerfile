# syntax=docker/dockerfile:1.7

# ---- builder ----
FROM composer:2 AS builder

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --no-scripts \
    --no-autoloader

COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev

# ---- runtime ----
# FrankenPHP keeps Streamable HTTP (MCP Inspector). Hardening aligned with project
# docker rules (non-root uid 10001, no curl/git in runtime, multi-stage, no secrets).
FROM dunglas/frankenphp:1-php8.5 AS runtime

WORKDIR /app

RUN groupadd --gid 10001 app \
    && useradd --uid 10001 --gid app --create-home --shell /usr/sbin/nologin app \
    && setcap -r /usr/local/bin/frankenphp \
    && mkdir -p /config/caddy /data/caddy /var/mcp-sessions \
    && chown -R app:app /config /data /var/mcp-sessions

COPY --from=builder --chown=app:app /app /app
COPY --chown=root:root Caddyfile /etc/caddy/Caddyfile

ENV XDG_CONFIG_HOME=/config \
    XDG_DATA_HOME=/data \
    SERVER_NAME=:8080 \
    MCP_SESSION_DIR=/var/mcp-sessions

USER app

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1:8080/mcp", false, stream_context_create(["http"=>["method"=>"GET","timeout"=>3,"ignore_errors"=>true]])) === false ? 1 : 0);'

CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]

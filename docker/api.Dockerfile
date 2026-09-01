# QRIVO — API container (local development only).
#
# Mirrors the locked stack in docs/ARCHITECTURE_RULES.md §1.1: PHP 8.3 + PDO.
# The application source is bind-mounted by docker-compose, so this image only
# provides the runtime — rebuild it only when the extension set changes.

FROM php:8.3-cli

# pdo_mysql is the only extension QRIVO requires beyond the PHP core
# (mbstring, ctype, openssl, json and filter are compiled in by default).
RUN docker-php-ext-install -j"$(nproc)" pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

# The official php images ship no active php.ini. Pin variables_order explicitly
# so that container environment variables reach $_ENV, which is what
# QRIVO\Infrastructure\Config\Config reads.
RUN printf 'variables_order=EGPCS\n' > /usr/local/etc/php/conf.d/zz-qrivo.ini

# Composer, used by the entrypoint to install dependencies on first boot.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install dependencies if the bind-mounted vendor/ is missing, then serve.
# `php -S` is the documented development server (backend/README.md); production
# deployment is out of scope for this compose file.
CMD ["sh", "-c", "\
  if [ ! -f vendor/autoload.php ]; then \
    echo '→ installing composer dependencies...'; \
    composer install --no-interaction --no-progress; \
  fi; \
  echo '→ QRIVO API listening on 0.0.0.0:8000'; \
  exec php -S 0.0.0.0:8000 -t public \
"]

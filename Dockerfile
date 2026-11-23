# One image for every role (web, queue worker, scheduler): only the command differs.

FROM dunglas/frankenphp:1-php8.5 AS base

RUN install-php-extensions pdo_pgsql bcmath intl pcntl opcache zip

WORKDIR /app


# Builds the PHP dependencies and the frontend. The Vite build runs `php artisan wayfinder:generate`,
# so this stage needs both PHP and Node.
FROM base AS build

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY --from=node:22-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY package.json package-lock.json .npmrc ./
RUN npm ci --audit false

COPY . .

# The key below is only a placeholder so that artisan can boot while building: nothing is encrypted with it.
RUN composer dump-autoload --optimize --no-dev \
    && APP_ENV=production APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= npm run build \
    && rm -rf node_modules


# What actually runs: the code, the vendor directory and the built assets, without Node.
FROM base AS runtime

COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
COPY --from=build /app /app

RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app/private \
    && chmod +x /usr/local/bin/entrypoint

ENTRYPOINT ["entrypoint"]
CMD ["--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]

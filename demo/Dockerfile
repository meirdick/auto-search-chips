# The package's workbench demo app, with seeded SQLite data.
# Build context: the repository root.
FROM composer:2 AS composer

FROM php:8.4-cli-alpine
COPY --from=composer /usr/bin/composer /usr/bin/composer
RUN apk add --no-cache git unzip

WORKDIR /app
# The dependencies go in their own layer: a code change does not upload them again.
COPY composer.json ./
RUN composer install --no-interaction --prefer-dist --no-progress --no-scripts --no-autoloader \
    && composer clear-cache

COPY . .
RUN composer dump-autoload --optimize \
    && vendor/bin/testbench package:discover \
    && vendor/bin/testbench workbench:create-sqlite-db \
    && vendor/bin/testbench migrate:fresh --force

# The search goes to Clairic through the Worker (see demo/src/index.ts).
# The container holds no Cloudflare token: the two values below are placeholders.
ENV SEARCH_CHIPS_ENGINE=auto \
    SEARCH_CHIPS_CF_TRANSPORT=direct \
    SEARCH_CHIPS_CF_URL=http://clairic.gateway/run \
    CLOUDFLARE_ACCOUNT_ID=gateway \
    CLOUDFLARE_API_TOKEN=gateway \
    SEARCH_CHIPS_CACHE=false \
    PHP_CLI_SERVER_WORKERS=4

EXPOSE 8080
CMD ["vendor/bin/testbench", "serve", "--host=0.0.0.0", "--port=8080", "--no-reload"]

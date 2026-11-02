FROM php:8.3-cli-bookworm AS base
RUN apt-get update && apt-get install -y --no-install-recommends \
      ca-certificates libonig-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libcurl4-openssl-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring bcmath pcntl sockets gd zip curl \
    && pecl install redis-6.3.0 && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/* /tmp/pear
ENV TZ=Asia/Shanghai
WORKDIR /app

FROM base AS dependencies
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-progress

FROM base AS runtime
ARG VERSION=store-bill-1.0.1
ARG VCS_REF=unknown
LABEL org.opencontainers.image.title="mpay_v2" \
      org.opencontainers.image.description="MPAY V2 with Alipay store bill receipt support" \
      org.opencontainers.image.source="https://github.com/niancheng/mpay_v2" \
      org.opencontainers.image.licenses="MIT" \
      org.opencontainers.image.version=$VERSION \
      org.opencontainers.image.revision=$VCS_REF
COPY --from=dependencies /app/vendor ./vendor
COPY . .
RUN mkdir -p runtime public/storage && chmod +x docker/entrypoint.sh
EXPOSE 8787
STOPSIGNAL SIGINT
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
  CMD php -r '$s=@fsockopen("127.0.0.1",8787,$e,$m,3);if(!$s)exit(1);fclose($s);'
ENTRYPOINT ["/app/docker/entrypoint.sh"]
CMD ["php", "start.php", "start"]

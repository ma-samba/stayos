FROM php:8.4-fpm-alpine

# ── Dépendances système ──────────────────────────────────────────────────────
RUN apk add --no-cache \
    git \
    curl \
    unzip \
    libpq-dev \
    libzip-dev \
    icu-dev \
    oniguruma-dev \
    libxml2-dev \
    linux-headers \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    $PHPIZE_DEPS

# ── Extensions PHP ───────────────────────────────────────────────────────────
RUN docker-php-ext-configure gd --with-freetype --with-jpeg

RUN docker-php-ext-install \
    pdo \
    pdo_pgsql \
    pgsql \
    zip \
    intl \
    opcache \
    bcmath \
    xml \
    mbstring \
    sysvsem \
    gd

# Redis via PECL
RUN pecl install redis && docker-php-ext-enable redis

# ── Composer ─────────────────────────────────────────────────────────────────
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ── Répertoire de travail ─────────────────────────────────────────────────────
COPY docker/php/fpm-pool.conf /usr/local/etc/php-fpm.d/www.conf

WORKDIR /var/www/backend

# ── Permissions ───────────────────────────────────────────────────────────────
RUN addgroup -g 1000 symfony && adduser -u 1000 -G symfony -s /bin/sh -D symfony
RUN chown -R symfony:symfony /var/www

USER symfony

EXPOSE 9000

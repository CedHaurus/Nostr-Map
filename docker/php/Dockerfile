FROM php:8.3-fpm-alpine

# System dependencies
RUN apk add --no-cache \
    libzip-dev \
    oniguruma-dev \
    gmp-dev \
    freetype-dev \
    libjpeg-turbo-dev \
    libpng-dev \
    libwebp-dev \
    $PHPIZE_DEPS

# Extensions à installer (curl et mbstring déjà incluses dans l'image de base)
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp && \
    docker-php-ext-install \
    pdo \
    pdo_mysql \
    zip \
    gmp \
    opcache \
    gd

# opcache configuration
RUN { \
    echo 'opcache.enable=1'; \
    echo 'opcache.memory_consumption=128'; \
    echo 'opcache.interned_strings_buffer=8'; \
    echo 'opcache.max_accelerated_files=4000'; \
    echo 'opcache.revalidate_freq=60'; \
} > /usr/local/etc/php/conf.d/opcache.ini

# PHP configuration
RUN { \
    echo 'upload_max_filesize=10M'; \
    echo 'post_max_size=10M'; \
    echo 'max_execution_time=30'; \
    echo 'display_errors=Off'; \
    echo 'log_errors=On'; \
    echo 'expose_php=Off'; \
    echo 'session.cookie_secure=On'; \
    echo 'session.cookie_httponly=On'; \
    echo 'session.cookie_samesite=Strict'; \
    echo 'session.use_strict_mode=On'; \
} > /usr/local/etc/php/conf.d/custom.ini

WORKDIR /var/www/html

USER www-data

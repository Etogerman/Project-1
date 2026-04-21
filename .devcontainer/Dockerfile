FROM php:8.4-cli-alpine

WORKDIR /var/www/html

# Install system dependencies and tools
RUN apk update && apk add --no-cache \
    bash \
    bash-completion \
    curl \
    git \
    htop \
    jq \
    nano \
    ncurses \
    nodejs \
    npm \
    openssh-client \
    starship \
    sudo \
    tree \
    unzip \
    vim \
    wget \
    zsh

# Install PHP extensions dependencies
RUN apk add --no-cache \
    freetype \
    icu-libs \
    libbz2 \
    libcurl \
    libjpeg-turbo \
    libpng \
    libpq \
    libxml2 \
    libxslt \
    libzip \
    linux-headers \
    oniguruma \
    postgresql-client \
    zlib \
    zstd-libs

# Build dependencies for PHP extensions
RUN apk add --no-cache --virtual .build-deps \
    autoconf \
    bzip2-dev \
    curl-dev \
    freetype-dev \
    gcc \
    icu-dev \
    libc-dev \
    libjpeg-turbo-dev \
    libpng-dev \
    libpq-dev \
    libxml2-dev \
    libxslt-dev \
    libzip-dev \
    make \
    oniguruma-dev \
    zlib-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        bcmath \
        curl \
        gd \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo \
        pdo_pgsql \
        soap \
        sockets \
        xsl \
        zip \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && apk del --no-network .build-deps

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- \
    --filename=composer \
    --install-dir=/usr/local/bin

# Configure bash with starship
RUN echo 'eval "$(starship init bash)"' >> /etc/bash/bashrc

# Set bash as default shell
ENV SHELL=/bin/bash

# Enable Xdebug coverage mode
ENV XDEBUG_MODE=coverage

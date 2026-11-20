FROM php:8.4-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    git \
    curl \
    libpng-dev \
    libxml2-dev \
    zip \
    unzip \
    supervisor \
    bash \
    mysql-client \
    mariadb-connector-c \
    nodejs \
    npm \
    chromium \
    nss \
    freetype \
    harfbuzz \
    ca-certificates \
    ttf-freefont

# Install Puppeteer globally without downloading bundled Chromium
RUN PUPPETEER_SKIP_CHROMIUM_DOWNLOAD=true npm install -g puppeteer

# Install PHP extensions via official installer helper
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

RUN install-php-extensions \
    pdo_mysql \
    mysqli \
    pdo_pgsql \
    pgsql \
    redis \
    bcmath \
    intl \
    zip \
    pcntl \
    opcache \
    gd

# Get latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

EXPOSE 9000
CMD ["php-fpm"]

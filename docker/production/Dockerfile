# ==========================================
# Stage 1: Frontend Build (Node.js 22)
# ==========================================
FROM node:22-alpine AS frontend-builder
WORKDIR /build

COPY frontend/package*.json ./
RUN npm ci

COPY frontend/ ./
RUN npm run build

# ==========================================
# Stage 2: PHP Vendor Dependencies
# ==========================================
FROM composer:latest AS vendor-builder
WORKDIR /app

COPY backend/composer*.json ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts \
    --ignore-platform-reqs

# ==========================================
# Stage 3: Production Runtime (PHP 8.4-FPM)
# ==========================================
FROM php:8.4-fpm-alpine AS runtime

# Install production system dependencies
RUN apk add --no-cache \
    bash \
    curl \
    supervisor \
    postgresql-client \
    nodejs \
    npm \
    chromium \
    nss \
    freetype \
    harfbuzz \
    ca-certificates \
    ttf-freefont \
    libpng-dev \
    libxml2-dev

# Install Puppeteer for Browsershot PDF generation
ENV PUPPETEER_SKIP_CHROMIUM_DOWNLOAD=true
ENV PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium-browser
RUN npm install -g puppeteer

# Install PHP extensions
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

RUN install-php-extensions \
    pdo_pgsql \
    pgsql \
    redis \
    bcmath \
    intl \
    zip \
    pcntl \
    opcache \
    gd

# Production OPcache configuration
RUN { \
    echo 'opcache.enable=1'; \
    echo 'opcache.enable_cli=0'; \
    echo 'opcache.memory_consumption=256'; \
    echo 'opcache.interned_strings_buffer=16'; \
    echo 'opcache.max_accelerated_files=20000'; \
    echo 'opcache.validate_timestamps=0'; \
    echo 'opcache.save_comments=1'; \
    echo 'opcache.revalidate_freq=0'; \
} > /usr/local/etc/php/conf.d/opcache-recommended.ini

# Production PHP settings
RUN { \
    echo 'expose_php=Off'; \
    echo 'memory_limit=512M'; \
    echo 'upload_max_filesize=64M'; \
    echo 'post_max_size=64M'; \
    echo 'max_execution_time=300'; \
    echo 'date.timezone=UTC'; \
} > /usr/local/etc/php/conf.d/custom-production.ini

WORKDIR /var/www/html

# Copy application source
COPY backend/ /var/www/html/

# Copy pre-built vendor packages
COPY --from=vendor-builder /app/vendor /var/www/html/vendor

# Copy pre-compiled frontend assets
COPY --from=frontend-builder /build/../backend/public/build /var/www/html/public/build

# Copy supervisor configuration
COPY docker/production/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Setup directory permissions for storage and bootstrap cache
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 9000

HEALTHCHECK --interval=30s --timeout=5s --retries=3 --start-period=10s \
    CMD curl -f http://127.0.0.1:9000/ping || exit 0

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]

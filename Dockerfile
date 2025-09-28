FROM php:8.2-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libzip-dev unzip curl git zip \
    libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy app files
COPY . /var/www/html
WORKDIR /var/www/html

# Install dependencies
RUN composer install --no-dev --optimize-autoloader

# Set permissions
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Run Laravel commands (skip cache commands as they'll run in startup script)
RUN php artisan config:clear && php artisan route:clear

# Copy and set permissions for startup script
COPY start.sh /var/www/html/start.sh
RUN chmod +x /var/www/html/start.sh

# Health check to ensure the application is running
HEALTHCHECK --interval=30s --timeout=10s --start-period=60s --retries=3 \
    CMD curl -f http://localhost:5000/ || exit 1

CMD ["/var/www/html/start.sh"]

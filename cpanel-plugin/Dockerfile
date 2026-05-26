FROM php:8.2-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libpq-dev \
    git \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions required for PostgreSQL and RabbitMQ (sockets)
RUN docker-php-ext-install pdo pdo_pgsql sockets

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy composer files first to leverage caching
COPY composer.json ./

# Install vendor dependencies
RUN composer install --no-interaction --no-plugins --no-scripts --prefer-dist

# Copy the rest of the application files
COPY . .

# Generate optimized autoloader
RUN composer dump-autoload --optimize

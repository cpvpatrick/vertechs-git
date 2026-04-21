FROM php:8.2-apache

# 1. Install system dependencies (libpq-dev is required for PostgreSQL)
RUN apt-get update && apt-get install -y \
    libpq-dev \
    && rm -rf /var/lib/apt/lists/*

# 2. Install PHP extensions (MySQL + PostgreSQL)
RUN docker-php-ext-install mysqli pdo pdo_pgsql pgsql

# 3. Copy your frontend into Apache
COPY pulsekit/ /var/www/html/

# 4. Enable Apache rewrite module
RUN a2enmod rewrite

# 5. Fix permissions for Apache
RUN chown -R www-data:www-data /var/www/html

# 6. Expose port 80
EXPOSE 80

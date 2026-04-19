FROM php:8.2-apache

# Install MySQL extension
RUN docker-php-ext-install mysqli

# Copy your frontend into Apache
COPY pulsekit/ /var/www/html/

# Enable rewrite
RUN a2enmod rewrite

# Fix permissions
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80

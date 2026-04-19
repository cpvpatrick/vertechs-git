FROM php:8.2-apache

COPY pulsekit/ /var/www/html/

RUN a2enmod rewrite
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80

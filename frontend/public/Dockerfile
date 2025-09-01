FROM php:8.2-apache

# Install PostgreSQL support
RUN apt-get update && apt-get install -y libpq-dev \    && docker-php-ext-install pgsql

# Optional: enable mod_rewrite
RUN a2enmod rewrite

COPY . /var/www/html/

# Expose default Apache port
EXPOSE 80
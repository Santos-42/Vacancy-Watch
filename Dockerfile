FROM php:8.2-apache

# Instal ekstensi pdo_mysql untuk koneksi Aiven
RUN docker-php-ext-install pdo_mysql

# Instal Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY . .

# Jalankan instalasi dependency
RUN composer install --no-dev --optimize-autoloader

# Set izin folder untuk cache data
RUN mkdir -p backend/data/raw && chmod -R 777 backend/data

# Gunakan port 80
EXPOSE 80

CMD ["apache2-foreground"]
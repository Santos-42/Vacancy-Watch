# Gunakan image PHP resmi dengan Apache
FROM php:8.2-apache

# RETASAN BARU: Instal alat dasar Linux agar Composer bisa mengunduh dan mengekstrak ZIP
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*

# RETASAN 1: Gunakan mlocati untuk mengunduh ekstensi yang sudah jadi, 
# bukan mengkompilasi dari awal. Ini menghemat 90% RAM dan Waktu Build.
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql

# Salin Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Salin semua file proyek
COPY . .

# RETASAN 2: Lepas batas memori Composer agar tidak mati mendadak
ENV COMPOSER_MEMORY_LIMIT=-1
RUN composer install --no-dev --optimize-autoloader

# Siapkan folder cache dengan izin penuh
RUN mkdir -p backend/data/raw && chmod -R 777 backend/data

# Buka port 80
EXPOSE 80

# Jalankan server
CMD ["apache2-foreground"]
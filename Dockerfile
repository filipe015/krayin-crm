FROM php:8.3-apache

# Extensões necessárias
RUN apt-get update && apt-get install -y \
    git curl zip unzip libpng-dev libonig-dev \
    libxml2-dev libzip-dev nodejs npm \
    && docker-php-ext-install pdo_mysql mbstring zip gd

# Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copia o projeto
WORKDIR /var/www/html
COPY . .

# Permissões
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html/storage

# Instala dependências
RUN composer install --no-dev --optimize-autoloader
RUN npm install && npm run build

# Apache apontando para /public
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/*.conf
RUN a2enmod rewrite

EXPOSE 80

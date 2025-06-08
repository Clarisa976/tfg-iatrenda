FROM php:8.2-cli

# Instalar dependencias y Composer
RUN apt-get update && apt-get install -y \
    libpq-dev \
    unzip \
    git \
    && docker-php-ext-install pdo pdo_pgsql \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Copiar solo archivos de composer
COPY composer.json composer.lock* ./

# Instalar dependencias FRESH en Docker
RUN composer install --no-dev --optimize-autoloader --no-scripts

# Copiar resto del código (SIN vendor/)
COPY src/ src/
COPY public/ public/

EXPOSE $PORT

CMD php -S 0.0.0.0:$PORT -t public public/index.php
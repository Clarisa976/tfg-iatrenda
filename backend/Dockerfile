FROM php:8.2-cli

# Instalar extensiones necesarias
RUN apt-get update && apt-get install -y \
    libpq-dev \
    unzip \
    && docker-php-ext-install pdo pdo_pgsql \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Copiar todo el código
COPY . .

# Dar permisos de ejecución a TU composer.phar
RUN chmod +x composer.phar

# Usar TU composer.phar (el que funciona)
RUN php composer.phar install --no-dev --optimize-autoloader

EXPOSE $PORT

CMD php -S 0.0.0.0:$PORT -t public public/index.php
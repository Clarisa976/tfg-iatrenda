FROM php:8.2-cli

# Instalar extensiones
RUN apt-get update && apt-get install -y \
    libpq-dev \
    unzip \
    && docker-php-ext-install pdo pdo_pgsql \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Copiar código
COPY . .

# Crear directorio vendor vacío por si acaso
RUN mkdir -p vendor

# Intentar usar composer.phar, pero no fallar si no funciona
RUN chmod +x composer.phar && \
    php composer.phar install --no-dev --optimize-autoloader --no-scripts || \
    echo "Composer failed, using vendor from git"

# Autoload manual como fallback
RUN echo "<?php require_once 'src/funciones_CTES_servicios.php';" > vendor/autoload.php

EXPOSE $PORT

CMD php -S 0.0.0.0:$PORT -t public public/index.php
FROM php:8.2-cli

# Instalar dependencias de PostgreSQL y extensiones PHP
RUN apt-get update && apt-get install -y \
    libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Copiar todo
COPY . .

EXPOSE $PORT

CMD php -S 0.0.0.0:$PORT -t public public/index.php
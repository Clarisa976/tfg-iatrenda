FROM php:8.2-cli

# Solo instalar extensiones necesarias
RUN docker-php-ext-install pdo pdo_pgsql

WORKDIR /app

# Copiar todo
COPY . .

EXPOSE $PORT

CMD php -S 0.0.0.0:$PORT -t public public/index.php
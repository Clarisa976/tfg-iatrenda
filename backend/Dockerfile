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

# Debug: ver qué archivos tenemos
RUN ls -la

# Debug: verificar composer.phar
RUN ls -la composer.phar || echo "composer.phar no encontrado"

# Dar permisos
RUN chmod +x composer.phar

# Debug: probar composer
RUN php composer.phar --version || echo "Error con composer.phar"

# Debug: validar composer.json
RUN php composer.phar validate || echo "composer.json inválido"

# Debug: diagnóstico
RUN php composer.phar diagnose || echo "Diagnóstico falló"

# Debug: mostrar composer.json
RUN cat composer.json

# Instalar con verbose para ver errores
RUN php composer.phar install --no-dev --optimize-autoloader --verbose

EXPOSE $PORT
CMD php -S 0.0.0.0:$PORT -t public public/index.php
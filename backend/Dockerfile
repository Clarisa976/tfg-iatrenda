FROM php:8.2-cli

# Instalar extensiones y herramientas necesarias
RUN apt-get update && apt-get install -y \
    libpq-dev \
    unzip \
    git \
    curl \
    && docker-php-ext-install pdo pdo_pgsql \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Instalar Composer oficial como backup
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Copiar archivos de composer primero
COPY composer.json composer.lock* ./

# Intentar con composer oficial primero
RUN composer install --no-dev --optimize-autoloader --no-scripts || echo "Official composer failed"

# Copiar el resto del código
COPY . .

# Si composer oficial falló, intentar con composer.phar
RUN chmod +x composer.phar && \
    (php composer.phar install --no-dev --optimize-autoloader --no-scripts || echo "composer.phar also failed")

# Verificar que vendor/autoload.php existe
RUN ls -la vendor/autoload.php || echo "No autoload found, creating manual one"

# Crear autoload manual si no existe
RUN if [ ! -f vendor/autoload.php ]; then \
        mkdir -p vendor && \
        echo "<?php require_once __DIR__ . '/../src/funciones_CTES_servicios.php';" > vendor/autoload.php; \
    fi

EXPOSE $PORT

CMD php -S 0.0.0.0:$PORT -t public public/index.php
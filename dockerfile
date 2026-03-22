FROM php:8.3-cli

RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    pkg-config \
    && docker-php-ext-install pdo pdo_sqlite \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

#CMD ["php", "-S", "0.0.0.0:8080"]
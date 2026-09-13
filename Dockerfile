FROM php:8.3-cli-alpine

RUN apk add --no-cache icu-data-full ca-certificates openssl \
    && update-ca-certificates \
    && docker-php-ext-install -j"$(nproc)" pdo pdo_mysql

WORKDIR /app
COPY . .

RUN chmod +x backend/bin/start.sh backend/bin/migrate.php

EXPOSE 8080
CMD ["sh", "backend/bin/start.sh"]

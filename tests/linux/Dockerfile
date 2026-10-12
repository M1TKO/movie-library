FROM composer:2 AS composer
FROM caddy:2.11.7 AS caddy
FROM debian:bookworm-slim
RUN apt-get update && apt-get install -y --no-install-recommends php8.2-cli php8.2-cgi php8.2-sqlite3 php8.2-mbstring php8.2-xml php8.2-curl unzip git curl ca-certificates ffmpeg && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY --from=caddy /usr/bin/caddy /usr/local/bin/caddy
WORKDIR /app

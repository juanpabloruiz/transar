# syntax=docker/dockerfile:1
FROM php:8.5-fpm-trixie AS php-base

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && install -d -m 0700 -o www-data -g www-data /var/lib/transar/jobs

WORKDIR /var/www

FROM denoland/deno:bin AS deno

FROM php-base AS worker-deps

RUN apt-get update \
    && apt-get install -y --no-install-recommends ffmpeg python3-venv libgomp1 \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pcntl \
    && python3 -m venv /opt/venv \
    && /opt/venv/bin/pip install --no-cache-dir --only-binary=:all: \
        faster-whisper 'yt-dlp[default,curl-cffi]' \
    && /opt/venv/bin/pip check \
    && install -d -m 0700 -o www-data -g www-data /var/cache/transar

COPY --from=deno /deno /usr/local/bin/deno

ENV HF_HOME=/var/cache/transar/huggingface \
    XDG_CACHE_HOME=/var/cache/transar \
    PYTHONUNBUFFERED=1

FROM worker-deps AS worker
COPY app/ /var/www/app/
USER www-data
CMD ["php", "/var/www/app/worker.php"]

FROM php-base AS php
RUN { \
        echo 'expose_php = Off'; \
        echo 'upload_max_filesize = 500M'; \
        echo 'post_max_size = 502M'; \
        echo 'max_file_uploads = 1'; \
        echo 'max_input_time = 300'; \
    } > "$PHP_INI_DIR/conf.d/transar.ini"
COPY app/jobs.php /var/www/app/jobs.php
COPY src/*.php /var/www/html/
USER www-data

FROM nginx:stable-alpine AS web
COPY nginx.conf /etc/nginx/nginx.conf
COPY src/ /var/www/html/

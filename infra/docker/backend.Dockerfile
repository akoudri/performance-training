# =============================================================================
# Resonance — image backend Laravel (PHP 8.3 + FPM)
# =============================================================================
# Phase 4-bis : le starter tourne en mode prod-like. Le container expose
# php-fpm sur :9000 (FastCGI), Nginx en frontal (cf. infra/nginx/) prend le
# trafic HTTP sur :8080 et fait fastcgi_pass vers ce service.
#
# Le master FPM tourne en root (default image), les workers en `app` (UID/GID
# alignés sur l'host via build args). Pour les commandes admin (composer,
# artisan), passer `docker compose exec -u app backend …` — le Makefile
# applique cela par défaut.
#
# OPcache est volontairement DÉSACTIVÉ (starter non optimisé).
# @perf-debt: opcache désactivé — résolu en J3 atelier "laravel-octane".
# @perf-debt: pm dynamic non tuné (max_children = 20) — pas l'objet de J1.
# =============================================================================

FROM php:8.3-fpm-alpine AS base

# ---- Dépendances système et extensions PHP ---------------------------------

RUN set -eux; \
    apk add --no-cache \
        bash \
        git \
        curl \
        unzip \
        gzip \
        icu-dev \
        postgresql-dev \
        postgresql16-client \
        libzip-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        oniguruma-dev; \
    apk add --no-cache --virtual .build-deps \
        autoconf \
        g++ \
        make \
        linux-headers; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        bcmath \
        intl \
        zip \
        gd \
        pcntl; \
    pecl install redis; \
    docker-php-ext-enable redis; \
    apk del .build-deps; \
    rm -rf /tmp/* /var/cache/apk/*

# ---- Composer --------------------------------------------------------------

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ---- Configuration PHP starter ---------------------------------------------
# OPcache laissé non-configuré (donc désactivé par défaut, contrat starter).

RUN { \
        echo "memory_limit=512M"; \
        echo "post_max_size=64M"; \
        echo "upload_max_filesize=64M"; \
        echo "max_execution_time=600"; \
    } > /usr/local/etc/php/conf.d/zz-resonance.ini

# ---- Configuration FPM (Phase 4-bis : prod-like) ---------------------------
# - listen 0.0.0.0:9000 : Nginx en réseau Docker doit pouvoir joindre.
# - user/group = app : worker pool tourne sous l'utilisateur non-root aligné
#   host (les fichiers que Laravel écrit dans storage/ et bootstrap/cache/
#   conservent ainsi le bon owner).
# - clear_env = no : laisse passer les env Compose (DB_*, REDIS_*, etc.).
# Master FPM en root (default image) → drop privileges vers `app` pour les
# workers via la directive user/group.

RUN { \
        echo "[www]"; \
        echo "user = app"; \
        echo "group = app"; \
        echo "listen = 0.0.0.0:9000"; \
        echo "listen.owner = app"; \
        echo "listen.group = app"; \
        echo "pm = dynamic"; \
        echo "pm.max_children = 20"; \
        echo "pm.start_servers = 4"; \
        echo "pm.min_spare_servers = 2"; \
        echo "pm.max_spare_servers = 8"; \
        echo "clear_env = no"; \
        echo "catch_workers_output = yes"; \
        echo "decorate_workers_output = no"; \
    } > /usr/local/etc/php-fpm.d/zz-resonance.conf

# ---- Utilisateur non-root aligné sur l'UID host ----------------------------

ARG UID=1000
ARG GID=1000

# Labels exposant les UID/GID bakés. Le Makefile s'en sert pour détecter
# qu'une image cache locale a été buildée pour un autre UID que celui du
# host courant (e.g. clone partagé entre dev Linux UID=1000 et dev Mac
# UID=501) et déclencher un rebuild automatique. Sans ces labels, FPM
# tournerait en UID bakée et échouerait à écrire dans le bind-mount
# `./backend` (storage/, bootstrap/cache/) avec "Permission denied".
LABEL resonance.host.uid="${UID}"
LABEL resonance.host.gid="${GID}"

RUN set -eux; \
    addgroup -g "${GID}" -S app; \
    adduser -u "${UID}" -G app -s /bin/bash -D app; \
    mkdir -p /app /home/app/.composer; \
    chown -R app:app /app /home/app

ENV COMPOSER_HOME=/home/app/.composer
ENV COMPOSER_ALLOW_SUPERUSER=0

WORKDIR /app

# Pas de `USER app` ici : le master FPM doit démarrer en root pour pouvoir
# basculer les workers en `app`. Pour les `docker compose exec`, passer
# `-u app` (le Makefile s'en charge).

EXPOSE 9000

# CMD par défaut de l'image php:8.3-fpm-alpine = ["php-fpm"] (foreground).
# On l'expose explicitement pour la lisibilité.
CMD ["php-fpm", "-F"]

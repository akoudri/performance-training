# =============================================================================
# Resonance — image backend Laravel (PHP 8.3 + Octane + FrankenPHP)
# =============================================================================
# Bascule j3-laravel : on remplace PHP-FPM (Phase 4-bis) par
# **Laravel Octane + FrankenPHP**. Le serveur web est désormais
# FrankenPHP (Caddy + module PHP intégré) qui sert directement HTTP sur
# :8000 — Nginx fait `proxy_pass http://backend:8000` au lieu de
# `fastcgi_pass backend:9000`.
#
# Choix de base : `dunglas/frankenphp:latest-php8.3`
#   - Image officielle FrankenPHP, alignée avec la doc Laravel Octane.
#   - Debian 12 slim sous le capot ; PHP 8.3 + frankenphp binaire déjà
#     en place dans /usr/local/bin/frankenphp.
#   - On y ajoute les extensions Laravel (pdo_pgsql, redis, intl, gd,
#     zip, pcntl, bcmath, gmp pour FrankenPHP).
# =============================================================================

FROM dunglas/frankenphp:latest-php8.3 AS base

# ---- Dépendances système et extensions PHP ---------------------------------
# install-php-extensions : helper officiel mlocati embarqué dans l'image
# dunglas/frankenphp pour installer rapidement les extensions PHP.
#
# Note : la version frankenphp embarquée dans l'image (1.1.5) est jugée
# incompatible par Laravel Octane (`WARN Your FrankenPHP binary version
# may be incompatible with Octane.`). Au premier démarrage, Octane
# télécharge automatiquement une version compatible dans /app/frankenphp
# (~ 50 Mo, ~ 5 s, persistant via le bind-mount). Les démarrages
# suivants sont instantanés.

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        bash \
        git \
        curl \
        unzip \
        gzip \
        postgresql-client \
        libzip-dev \
        libpng-dev \
        libjpeg-dev \
        libfreetype-dev \
        libicu-dev \
        libpq-dev \
        libonig-dev; \
    install-php-extensions \
        pdo_pgsql \
        redis \
        intl \
        gd \
        zip \
        pcntl \
        bcmath \
        opcache; \
    rm -rf /var/lib/apt/lists/*

# ---- Composer --------------------------------------------------------------

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ---- Configuration PHP — Octane prod-like ----------------------------------
# @perf-fix: OPcache activé + JIT tracing 64 Mo. Sous Octane le worker vit
# longtemps : OPcache stocke le bytecode compilé dans la mémoire partagée
# du process, JIT compile les hot paths en code natif au fil de l'exécution.
# `validate_timestamps=1` garde le mode dev confortable (modifs source
# détectées toutes les 2 s) — en prod on poserait =0 + déploiement
# atomique avec `opcache_reset()`.

RUN { \
        echo "memory_limit=512M"; \
        echo "post_max_size=64M"; \
        echo "upload_max_filesize=64M"; \
        echo "max_execution_time=600"; \
        echo ""; \
        echo "; OPcache + JIT (j3-laravel @perf-fix)"; \
        echo "opcache.enable=1"; \
        echo "opcache.enable_cli=1"; \
        echo "opcache.memory_consumption=256"; \
        echo "opcache.interned_strings_buffer=16"; \
        echo "opcache.max_accelerated_files=20000"; \
        echo "opcache.validate_timestamps=1"; \
        echo "opcache.revalidate_freq=2"; \
        echo "opcache.jit=tracing"; \
        echo "opcache.jit_buffer_size=64M"; \
    } > /usr/local/etc/php/conf.d/zz-resonance.ini

# ---- Utilisateur non-root aligné sur l'UID host ----------------------------

ARG UID=1000
ARG GID=1000

RUN set -eux; \
    if getent group "${GID}" >/dev/null; then \
        existing_group=$(getent group "${GID}" | cut -d: -f1); \
        groupmod -n app "${existing_group}"; \
    else \
        groupadd -g "${GID}" app; \
    fi; \
    if id -u "${UID}" >/dev/null 2>&1; then \
        existing_user=$(getent passwd "${UID}" | cut -d: -f1); \
        usermod -l app -g app -d /home/app -m "${existing_user}"; \
    else \
        useradd -m -u "${UID}" -g app -s /bin/bash app; \
    fi; \
    mkdir -p /app /home/app/.composer; \
    chown -R app:app /app /home/app

ENV COMPOSER_HOME=/home/app/.composer
ENV COMPOSER_ALLOW_SUPERUSER=0

WORKDIR /app

# FrankenPHP en mode "non-root" via la variable de l'image officielle.
# Cf. https://frankenphp.dev/docs/production/#docker
ENV SERVER_NAME=":8000"

EXPOSE 8000

# CMD = optimize + octane:start. `php artisan optimize` chaîne
# config:cache, route:cache, view:cache, event:cache — tous idempotents,
# stockés dans bootstrap/cache/ (bind-mount, persistant entre restarts).
# Cette pré-compilation libère le worker Octane des coûts de bootstrap
# au cold start (~ 100-200 ms).
#
# Le supervisor Octane spawn N workers FrankenPHP selon
# `config/octane.php` ; en dev `--workers=auto --max-requests=500`
# recycle le worker régulièrement pour débusquer les fuites mémoire
# applicatives (cf. §H "Points de vigilance Octane" du brief j3-laravel).
CMD ["sh", "-c", "php artisan optimize && exec php artisan octane:start \
     --server=frankenphp \
     --host=0.0.0.0 \
     --port=8000 \
     --workers=auto \
     --max-requests=500"]

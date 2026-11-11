# Supertext TYPO3 demo: TYPO3 14.3 + Camino demo content + this extension.
# Build context is the repository root:
#   docker build -f demo/Dockerfile -t supertext-typo3-demo .
# Persistent state lives in /data (mount a volume there). See demo/README.md.

FROM php:8.5-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev \
      graphicsmagick unzip git \
 && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
 && docker-php-ext-install -j"$(nproc)" intl zip gd \
 && { php -m | grep -q 'Zend OPcache' || docker-php-ext-install opcache; } \
 && a2enmod rewrite headers expires deflate \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    TYPO3_CONTEXT=Production

WORKDIR /var/www/typo3

# Dependencies first (cached), then the extension itself.
COPY demo/composer.json demo/composer.lock ./
COPY . packages/supertext_translation/
RUN rm -rf packages/supertext_translation/demo \
 && composer install --no-dev --no-interaction --no-progress --optimize-autoloader \
 && cp vendor/typo3/cms-install/Resources/Private/FolderStructureTemplateFiles/root-htaccess public/.htaccess

COPY demo/additional.php config/system/additional.php
COPY demo/site-config.yaml /opt/demo/site-config.yaml
COPY demo/apache.conf /etc/apache2/sites-available/000-default.conf
COPY demo/php.ini /usr/local/etc/php/conf.d/typo3.ini
COPY demo/entrypoint.sh /usr/local/bin/typo3-entrypoint
RUN chmod +x /usr/local/bin/typo3-entrypoint

# No VOLUME instruction: Railway rejects it and mounts its own volume at /data.
# Locally: docker run -v typo3demo:/data ...
ENTRYPOINT ["typo3-entrypoint"]
CMD ["apache2-foreground"]

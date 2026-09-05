FROM php:8.4-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libsqlite3-dev \
    && docker-php-ext-install pdo_sqlite \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

ENV APACHE_DOCUMENT_ROOT=/var/www/echo/backend/public \
    ECHO_APP_ENV=production \
    ECHO_DSN=sqlite:/var/lib/echo/echo.sqlite

WORKDIR /var/www/echo
COPY . /var/www/echo

RUN sed -ri -e "s!/var/www/html!${APACHE_DOCUMENT_ROOT}!g" \
        /etc/apache2/sites-available/*.conf \
        /etc/apache2/apache2.conf \
        /etc/apache2/conf-available/*.conf \
    && printf '<Directory \"%s\">\nAllowOverride All\nRequire all granted\n</Directory>\n' "${APACHE_DOCUMENT_ROOT}" > /etc/apache2/conf-available/echo.conf \
    && a2enconf echo \
    && sed -ri 's/^ServerTokens .*/ServerTokens Prod/; s/^ServerSignature .*/ServerSignature Off/' /etc/apache2/conf-available/security.conf \
    && printf 'expose_php=Off\ndisplay_errors=Off\nlog_errors=On\n' > /usr/local/etc/php/conf.d/echo.ini \
    && mkdir -p /var/lib/echo \
    && chown -R www-data:www-data /var/lib/echo \
    && chown -R root:root /var/www/echo

VOLUME ["/var/lib/echo"]
EXPOSE 80

HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD php -r '$r=@file_get_contents("http://127.0.0.1/api/health"); exit($r !== false && str_contains($r, "\"status\":\"ok\"") ? 0 : 1);'

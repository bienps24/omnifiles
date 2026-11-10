FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

ENV APACHE_DOCUMENT_ROOT=/var/www/app/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf \
    && echo "ServerName localhost" >> /etc/apache2/apache2.conf \
    && { echo 'output_buffering = Off'; echo 'max_execution_time = 120'; echo 'memory_limit = 256M'; } > /usr/local/etc/php/conf.d/omnifiles.ini

WORKDIR /var/www/app
COPY . /var/www/app
RUN chmod +x /var/www/app/docker/start.sh

CMD ["/var/www/app/docker/start.sh"]

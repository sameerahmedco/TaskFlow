# TaskFlow v3 optional self-hosted runtime. TLS is terminated at a trusted proxy.
# The runtime container excludes the source screenshot gallery.
FROM php:8.3-apache
RUN apt-get update && apt-get install -y --no-install-recommends libonig-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring opcache \
    && a2enmod rewrite headers deflate expires \
    && rm -rf /var/lib/apt/lists/*
RUN printf '%s\n' \
    'upload_max_filesize=8M' 'post_max_size=10M' 'memory_limit=256M' \
    'display_errors=Off' 'log_errors=On' \
    'opcache.enable=1' 'opcache.memory_consumption=64' \
    'opcache.interned_strings_buffer=8' 'opcache.max_accelerated_files=4000' \
    'opcache.validate_timestamps=1' 'opcache.revalidate_freq=2' \
    > /usr/local/etc/php/conf.d/zz-taskflow-production.ini
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html/config \
    && chmod 750 /var/www/html/config \
    && printf '%s\n' '<Directory /var/www/html>' 'AllowOverride All' 'Require all granted' '</Directory>' \
       > /etc/apache2/conf-enabled/taskflow.conf
EXPOSE 80

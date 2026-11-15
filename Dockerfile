# Small optional Apache runtime for evaluations. Provision TLS/MySQL outside this image.
FROM php:8.3-apache
RUN apt-get update && apt-get install -y --no-install-recommends libonig-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html/config \
    && chmod 750 /var/www/html/config \
    && printf '<Directory /var/www/html>\n AllowOverride All\n Require all granted\n</Directory>\n' > /etc/apache2/conf-enabled/taskflow.conf
EXPOSE 80

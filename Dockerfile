FROM php:8.2-apache

# ===============================
# DEPENDENCIAS DEL SISTEMA
# ===============================
RUN apt-get update && apt-get install -y \
    libzip-dev \
    zip \
    unzip \
    cron \
    && rm -rf /var/lib/apt/lists/*

# ===============================
# EXTENSIONES PHP
# ===============================
RUN docker-php-ext-configure zip \
    && docker-php-ext-install zip pdo pdo_mysql mysqli

# ===============================
# AJUSTES PHP PARA CARGAS
# ===============================
RUN { \
    echo 'upload_max_filesize=128M'; \
    echo 'post_max_size=128M'; \
    echo 'max_file_uploads=20'; \
    echo 'memory_limit=512M'; \
    echo 'max_execution_time=300'; \
    echo 'max_input_time=300'; \
} > /usr/local/etc/php/conf.d/uploads.ini

# ===============================
# CONFIGURAR ZONA HORARIA
# ===============================
RUN ln -snf /usr/share/zoneinfo/America/Bogota /etc/localtime \
    && echo "America/Bogota" > /etc/timezone

# ===============================
# HABILITAR MOD_REWRITE
# ===============================
RUN a2enmod rewrite

# ===============================
# CONFIGURAR APACHE EN PUERTO 3010
# ===============================
RUN sed -i 's/Listen 80/Listen 3010/' /etc/apache2/ports.conf \
    && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:3010>/' /etc/apache2/sites-available/000-default.conf

# ===============================
# PERMITIR .htaccess
# ===============================
RUN echo '<Directory /var/www/html>\n\
  AllowOverride All\n\
  Require all granted\n\
</Directory>' \
> /etc/apache2/conf-available/allow-html.conf \
&& a2enconf allow-html

# ===============================
# COPIAR PROYECTO
# ===============================
COPY . /var/www/html/

# ===============================
# CONFIGURAR CRON
# ===============================
RUN { \
        echo 'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'; \
        echo 'SHELL=/bin/sh'; \
        echo '0 20 * * * root /usr/local/bin/php /var/www/html/core/cierre_jornada_automatico.php >> /var/log/ncrm_cierre_jornada.log 2>&1'; \
        echo '10 20 * * * root /usr/local/bin/php /var/www/html/core/chat_purge.php >> /var/log/ncrm_chat_purge.log 2>&1'; \
        echo '20 20 * * * root /usr/local/bin/php /var/www/html/core/chat_image_purge.php >> /var/log/ncrm_chat_image_purge.log 2>&1'; \
    } > /etc/cron.d/ncrm-tareas \
    && chmod 0644 /etc/cron.d/ncrm-tareas \
    && crontab /etc/cron.d/ncrm-tareas

# ===============================
# PERMISOS
# ===============================
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod +x /var/www/html/docker-start.sh

EXPOSE 3010

CMD ["/var/www/html/docker-start.sh"]

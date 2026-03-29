# ── ROMAnalyzer Backend ─────────────────────────────────────────────
# Apache HTTP Server + PHP 8.2 + SQLite 3
# Deploy: Render.com (Docker service)
# ────────────────────────────────────────────────────────────────────
FROM php:8.2-apache

# Instalar extensión PDO + SQLite
RUN docker-php-ext-install pdo pdo_sqlite

# Activar módulos de Apache necesarios
RUN a2enmod rewrite headers

# Directorio persistente para la base de datos SQLite
# En Render se monta como Disk en /data (ver render.yaml)
RUN mkdir -p /data && chown www-data:www-data /data

# Copiar configuración personalizada de Apache
COPY apache.conf /etc/apache2/sites-available/000-default.conf

# Copiar la API PHP
COPY api/ /var/www/html/api/

# Ajustar permisos del directorio web
RUN chown -R www-data:www-data /var/www/html

# Script de arranque: ajusta el puerto que Render asigna dinámicamente
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]

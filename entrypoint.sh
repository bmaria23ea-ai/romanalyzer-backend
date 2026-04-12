#!/bin/bash
set -e

PORT=${PORT:-80}

echo "[ROMAnalyzer] Configurando Apache en el puerto $PORT"

# Reemplaza puerto en ports.conf
sed -i "s/Listen 80/Listen $PORT/g" /etc/apache2/ports.conf

# Reemplaza puerto en el VirtualHost
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:$PORT>/g" \
    /etc/apache2/sites-available/000-default.conf

# Asegura permisos de escritura en /data para SQLite
chown -R www-data:www-data /data 2>/dev/null || true

echo "[ROMAnalyzer] Apache listo — arrancando..."
exec apache2-foreground

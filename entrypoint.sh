#!/bin/bash
# ─────────────────────────────────────────────────────────────────────
# entrypoint.sh
# Render.com asigna un puerto dinámico via la variable de entorno PORT.
# Apache por defecto escucha en 80; este script adapta la configuración
# antes de arrancar para que coincida con lo que Render espera.
# ─────────────────────────────────────────────────────────────────────
set -e

PORT=${PORT:-80}

echo "[ROMAnalyzer] Configurando Apache en el puerto $PORT"

# Reemplazar puerto en ports.conf
sed -i "s/Listen 80/Listen $PORT/g" /etc/apache2/ports.conf

# Reemplazar puerto en el VirtualHost
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:$PORT>/g" \
    /etc/apache2/sites-available/000-default.conf

# Asegurar permisos de escritura en /data para SQLite
chown -R www-data:www-data /data 2>/dev/null || true

echo "[ROMAnalyzer] Apache listo — arrancando..."
exec apache2-foreground

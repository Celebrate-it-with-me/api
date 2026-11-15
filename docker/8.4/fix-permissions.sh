#!/usr/bin/env bash

echo "Fixing permissions..."

# Cambiar ownership de todo a sail:sail
find /var/www/html \( -name .git -o -name node_modules \) -prune -o -exec chown sail:sail {} +

# Permisos correctos para directorios (775)
find /var/www/html \( -name .git -o -name node_modules \) -prune -o -type d -exec chmod 775 {} +

# Permisos correctos para archivos (664)
find /var/www/html \( -name .git -o -name node_modules \) -prune -o -type f -exec chmod 664 {} +

# Permisos especiales para storage y bootstrap/cache
chmod -R 775 /var/www/html/storage
chmod -R 775 /var/www/html/bootstrap/cache

# Permisos para vendor (importante para Pest)
if [ -d "/var/www/html/vendor" ]; then
    chmod -R 775 /var/www/html/vendor
fi

echo "Permissions fixed!"

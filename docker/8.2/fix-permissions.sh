#!/usr/bin/env bash

echo "Fixing permissions..."

# Cambiar ownership de todo a sail:sail
chown -R sail:sail /var/www/html

# Permisos correctos para directorios (775)
find /var/www/html -type d -exec chmod 775 {} \;

# Permisos correctos para archivos (664)
find /var/www/html -type f -exec chmod 664 {} \;

# Permisos especiales para storage y bootstrap/cache
chmod -R 775 /var/www/html/storage
chmod -R 775 /var/www/html/bootstrap/cache

# Permisos para vendor (importante para Pest)
if [ -d "/var/www/html/vendor" ]; then
    chmod -R 775 /var/www/html/vendor
fi

echo "Permissions fixed!"

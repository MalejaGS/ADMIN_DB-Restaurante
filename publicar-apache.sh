#!/usr/bin/env bash
set -euo pipefail
origen=/home/estudiante/restaurante
destino=/var/www/html/restaurante
if [ "$(id -u)" -ne 0 ]; then
    echo 'Ejecute: sudo bash /home/estudiante/restaurante/publicar-apache.sh' >&2
    exit 1
fi
archivos=(etc/parametros.php lib/libreria.php lib/auth.php lib/pedidos.php lib/reservas.php lib/menu.php lib/reportes.php lib/restaurante.php lib/ui.php lib/programa.js css/estilo.css esqueleto.html index.php)
for archivo in "${archivos[@]}"; do
    test -f "$origen/$archivo"
    if [[ "$archivo" == *.php ]]; then php -l "$origen/$archivo" >/dev/null; fi
done
mkdir -p /var/backups
respaldo="/var/backups/restaurante-apache-$(date +%Y%m%d-%H%M%S).tar.gz"
umask 077
tar -czf "$respaldo" -C /var/www/html restaurante
chmod 600 "$respaldo"
for archivo in "${archivos[@]}"; do
    install -D -o root -g root -m 0644 "$origen/$archivo" "$destino/$archivo"
done
if command -v restorecon >/dev/null 2>&1; then restorecon -R "$destino"; fi
respuesta=$(curl --noproxy '*' --fail --silent --show-error http://127.0.0.1/restaurante/index.php)
if [[ "$respuesta" != *'Iniciar sesión'* ]]; then
    echo "Archivos publicados, pero la respuesta no muestra el inicio de sesión. Respaldo: $respaldo" >&2
    echo 'Revise los registros de Apache/PHP antes de continuar.' >&2
    exit 1
fi
printf 'Publicación comprobada: Apache muestra Iniciar sesión.\nRespaldo anterior: %s\n' "$respaldo"

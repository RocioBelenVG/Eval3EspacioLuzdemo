#!/bin/sh
# Recorre el panel con una sesión simulada del rol indicado (sin contraseñas) y muestra
# el código HTTP, el tamaño y los errores PHP de cada pantalla.   Uso: sh tools/recorrer_panel.sh [rol]
cd "$(dirname "$0")/.." || exit 1
php tools/recorrer_panel.php "${1:-admin}" | while IFS="$(printf '	')" read -r ruta usuario; do
  salida=$(php -d display_errors=1 -d error_reporting=32767 tools/recorrer_ruta.php "$PWD" "$ruta" "$usuario" 2>&1)
  cabecera=$(printf '%s
' "$salida" | head -n 1)
  bytes=$(printf '%s' "$salida" | wc -c)
  errores=$(printf '%s
' "$salida" | grep -oE '(Fatal error|Warning|Notice|Deprecated|Uncaught)[^<]{0,200}' | sort -u | head -n 3)
  printf '%-60s %-28s %8s bytes
' "$ruta" "$cabecera" "$bytes"
  [ -n "$errores" ] && printf '%s
' "$errores" | sed 's/^/    ! /'
done

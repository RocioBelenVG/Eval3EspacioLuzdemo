#!/bin/sh
# Respaldo y prueba de restauración en el hosting (fuera de Docker).
#   sh tools/respaldo.sh respaldar           → ~/respaldos_espacioluzdemo/espacioluz_AAAAMMDD_HHMMSS.sql.gz (retención 14 días)
#   sh tools/respaldo.sh restaurar ARCHIVO   → reemplaza la base por el respaldo indicado
#   sh tools/respaldo.sh probar              → respalda, simula una pérdida (vacía documento_pagos y evoluciones),
#                                              restaura y compara los conteos; deja el registro en la salida estándar
# Las credenciales se leen del .env con PHP y se pasan a mysqldump/mysql en un archivo temporal 0600.
set -eu
cd "$(dirname "$0")/.."
DESTINO="${RESPALDOS_DIR:-$HOME/respaldos_espacioluzdemo}"
mkdir -p "$DESTINO"
chmod 700 "$DESTINO"
CNF=$(mktemp)
trap 'rm -f "$CNF"' EXIT
php -r 'require "lib/env.php"; printf("[client]\nuser=%s\npassword=%s\nhost=%s\nport=%s\n", env("DB_USER"), env("DB_PASS"), env("DB_HOST","localhost"), env("DB_PORT","3306"));' > "$CNF"
BD=$(php -r 'require "lib/env.php"; echo env("DB_NAME");')

respaldar() {
  ARCHIVO="$DESTINO/espacioluz_$(date +%Y%m%d_%H%M%S).sql.gz"
  mysqldump --defaults-extra-file="$CNF" --single-transaction --routines --triggers --no-tablespaces "$BD" | gzip > "$ARCHIVO.tmp"
  mv "$ARCHIVO.tmp" "$ARCHIVO"
  find "$DESTINO" -name 'espacioluz_*.sql.gz' -mtime +14 -delete
  echo "$ARCHIVO"
}
restaurar() {
  gunzip < "$1" | mysql --defaults-extra-file="$CNF" "$BD"
}
conteos() {
  mysql --defaults-extra-file="$CNF" -N "$BD" -e "SELECT 'reservas', COUNT(*) FROM reservas UNION ALL SELECT 'pacientes', COUNT(*) FROM pacientes
    UNION ALL SELECT 'boletas', COUNT(*) FROM boletas UNION ALL SELECT 'documento_pagos', COUNT(*) FROM documento_pagos
    UNION ALL SELECT 'evoluciones', COUNT(*) FROM evoluciones UNION ALL SELECT 'auditoria', COUNT(*) FROM auditoria
    UNION ALL SELECT 'disparadores', COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()
    UNION ALL SELECT 'vistas', COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE()"
}

case "${1:-}" in
  respaldar) respaldar ;;
  restaurar) restaurar "$2"; echo "Restaurado $2" ;;
  probar)
    echo "== Prueba de respaldo y restauración · $(date -Iseconds)"
    conteos > /tmp/el_antes.txt; echo "-- Conteos antes"; cat /tmp/el_antes.txt
    T0=$(date +%s); A=$(respaldar); T1=$(date +%s)
    echo "-- Respaldo: $A ($(du -h "$A" | cut -f1)) en $((T1 - T0)) s"
    mysql --defaults-extra-file="$CNF" "$BD" -e "SET @app_auditoria = 0; DELETE FROM documento_pagos; DELETE FROM evoluciones;"
    echo "-- Incidente simulado: se vaciaron documento_pagos y evoluciones"; conteos | grep -E 'documento_pagos|evoluciones'
    T2=$(date +%s); restaurar "$A"; T3=$(date +%s)
    conteos > /tmp/el_despues.txt; echo "-- Conteos después de restaurar (en $((T3 - T2)) s)"; cat /tmp/el_despues.txt
    if diff -q /tmp/el_antes.txt /tmp/el_despues.txt >/dev/null; then echo "RESULTADO: OK, los conteos coinciden"; else echo "RESULTADO: DIFERENCIAS"; diff /tmp/el_antes.txt /tmp/el_despues.txt; exit 1; fi
    ;;
  *) echo "Uso: sh tools/respaldo.sh respaldar|restaurar ARCHIVO|probar"; exit 2 ;;
esac

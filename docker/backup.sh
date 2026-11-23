#!/bin/sh
# Servicio auxiliar «backup»: respaldo comprimido al iniciar y luego cada BACKUP_INTERVAL segundos
# (por defecto, diario), con retención de BACKUP_RETENTION_DAYS días (por defecto, 14).
# Respaldo puntual:  docker compose exec -e BACKUP_UNA_VEZ=1 backup sh /backup.sh
# Se quita el DEFINER de vistas y disparadores para que el respaldo se restaure en cualquier servidor.
# Restaurar:         gunzip < /backups/espacioluz_AAAAMMDD_HHMM.sql.gz | mariadb -h db -u root -p espacioluzdemo
set -eu
INTERVALO="${BACKUP_INTERVAL:-86400}"
RETENCION="${BACKUP_RETENTION_DAYS:-14}"
while true; do
  ARCHIVO="/backups/espacioluz_$(date +%Y%m%d_%H%M%S).sql.gz"
  if mariadb-dump --single-transaction --routines --triggers -h db -u root -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" \
       | sed -e 's/DEFINER=`[^`]*`@`[^`]*`//g' | gzip > "$ARCHIVO.tmp"; then
    mv "$ARCHIVO.tmp" "$ARCHIVO"
    echo "$(date -Iseconds) respaldo OK: $ARCHIVO ($(du -h "$ARCHIVO" | cut -f1))"
  else
    rm -f "$ARCHIVO.tmp"
    echo "$(date -Iseconds) ERROR: falló el respaldo" >&2
  fi
  find /backups -name 'espacioluz_*.sql.gz' -mtime +"$RETENCION" -delete
  [ "${BACKUP_UNA_VEZ:-0}" = "1" ] && exit 0   # respaldo puntual (antes de actualizar o en CI)
  sleep "$INTERVALO"
done

#!/bin/sh
# Servicio auxiliar «migrate»: deja la base lista y termina (se ejecuta en cada docker compose up).
#   1. Aplica las migraciones pendientes (en una base vacía, V1 crea el esquema y los catálogos).
#   2. Solo si SEED_DEMO=1 (por defecto) y hay menos de 500 atenciones: genera 12 meses de datos
#      sintéticos y las cuentas de demostración por rol (contraseña desde DEMO_PASSWORD).
#      En producción se usa SEED_DEMO=0: solo migraciones.
set -eu
cd /var/www/html

echo "→ Migraciones"
vendor/bin/phinx migrate -c phinx.php

if [ "${SEED_DEMO:-1}" != "1" ]; then
  echo "✓ Base lista (SEED_DEMO=0: sin datos ni cuentas de demostración)"
  exit 0
fi

N=$(php -r 'require "lib/env.php"; $p = new PDO("mysql:host=".env("DB_HOST").";port=".env("DB_PORT","3306").";dbname=".env("DB_NAME"), env("DB_USER"), env("DB_PASS")); echo (int) $p->query("SELECT COUNT(*) FROM reservas")->fetchColumn();')
echo "→ Atenciones existentes: $N"
if [ "$N" -lt 500 ]; then
  echo "→ Generando datos sintéticos (12 meses) y cuentas de demostración"
  SEED_PERMITIDO=1 vendor/bin/phinx seed:run -c phinx.php -s DatosSinteticos
fi
echo "✓ Base lista"

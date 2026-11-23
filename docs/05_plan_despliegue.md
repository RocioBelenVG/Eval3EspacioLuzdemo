# Plan de despliegue

## 1. Entornos

| Entorno | Dónde | Configuración | Datos |
|---|---|---|---|
| Desarrollo | `docker compose up` en el equipo del equipo de trabajo | `APP_ENV=development`, `SEED_DEMO=1`, IA opcional, `MAIL_MODO=archivo` | Sintéticos (12 meses) |
| Pruebas (CI) | GitHub Actions: servicio MariaDB + `docker compose` efímero | `APP_ENV=testing`; secretos generados por ejecución y enmascarados | Sintéticos; cada prueba en transacción revertida |
| Demostración / producción | Hoy: Hostinger (`trivoxa.cl/espacioluzdemo`, LiteSpeed + PHP 8.3 + MariaDB). Propuesta: VPS con Docker | `APP_ENV=production`, `SEED_DEMO=0` en un despliegue real, `CORS_ORIGENES` vacío, HTTPS obligatorio | Demo: sintéticos. Real: datos del centro (nunca sintéticos) |

Diferencias clave: en producción no se cargan datos de demostración ni cuentas demo; `display_errors=Off` siempre; el
modo DTE es **demo** en esta copia (para emitir de verdad se usaría el sistema EspacioLuz original, no esta demo).

## 2. Infraestructura propuesta

* **VPS** 2 vCPU, 4 GB RAM, 60 GB SSD (p. ej. Hostinger KVM 2 o DigitalOcean Basic), Ubuntu 24.04 LTS con Docker 27.
* Contenedores: `web` (nginx, único puerto publicado), `app` (PHP-FPM), `db` (MariaDB, red interna), `migrate`, `backup`.
* Red: `frontend` (publicada), `backend` (interna, sin internet), `egress` (solo `app`, para la IA).
* Puertos: 443/80 en un proxy inverso (Caddy o Traefik) con certificado **Let's Encrypt** automático → `web:8080`.
* Dominio: `demo.espacioluz.cl` (registro CNAME/A hacia el VPS). Volúmenes: `db-data`, `storage`, `imagenes`, `backups`.

## 3. Pasos de despliegue

```bash
# 1. Servidor: Docker y usuario sin privilegios
curl -fsSL https://get.docker.com | sh && sudo usermod -aG docker despliegue
# 2. Código
git clone https://github.com/<organizacion>/espacioluz-demo.git && cd espacioluz-demo
# 3. Secretos
cp .env.example .env && nano .env            # DB_PASS, DB_ROOT_PASSWORD, JWT_SECRET (openssl rand -hex 32), DEMO_PASSWORD, GROQ_API_KEY
chmod 600 .env
# 4. Levantar (migraciones y datos se aplican solos con el servicio migrate)
docker compose pull || docker compose build
docker compose up -d
# 5. Verificar
curl -fsS http://localhost:8080/api/v1/health
docker compose ps
```

Hosting actual (sin Docker): `git push hosting main` (el repositorio del servidor usa `receive.denyCurrentBranch=updateInstead`),
luego por SSH `composer install --no-dev` y `vendor/bin/phinx migrate -c phinx.php`.

## 4. Secretos y variables por entorno

| Variable | Desarrollo | CI | Producción |
|---|---|---|---|
| `DB_PASS`, `DB_ROOT_PASSWORD` | `.env` local | `openssl rand` + `::add-mask::` | `.env` 0600 o *secrets* de Docker |
| `JWT_SECRET` (≥ 32) | `.env` local | generado por ejecución | rotación semestral (invalida los tokens vigentes) |
| `GROQ_API_KEY` | opcional | vacía (se prueba el 503) | secreto del proveedor; rotar si se expone |
| `DEMO_PASSWORD` | `.env` | generada | no aplica (`SEED_DEMO=0`) |
| `CORS_ORIGENES` | vacío | vacío | solo dominios propios si se integra otra app |

`.env` nunca entra al repositorio ni a la imagen (`.gitignore`, `.dockerignore`); `.env.example` documenta todas las variables sin valores reales.

## 5. Actualización y reversión

* **Actualización:** CI publica imágenes etiquetadas con el SHA del commit. En el servidor: respaldo puntual
  (`docker compose exec -e BACKUP_UNA_VEZ=1 backup sh /backup.sh`) → `IMAGE_TAG=<sha> docker compose up -d` → `migrate`
  aplica migraciones nuevas → verificación de `/api/v1/health` y prueba de humo.
* **Rollback de aplicación:** volver a la etiqueta anterior: `IMAGE_TAG=<sha_anterior> docker compose up -d`.
* **Rollback de base de datos:** cada migración V2 a V6 tiene `down()`; `vendor/bin/phinx rollback -c phinx.php -t <versión>`.
  Probado en el hosting (V6 y V5 revertidas y re-aplicadas, `docs/evidencias/migraciones/reversion_hosting.txt`) y en cada
  ejecución del pipeline (hasta V1 y vuelta). Si una migración destruye datos, se restaura el respaldo previo.

## 6. Respaldo y recuperación

Diario a las 03:00 (servicio `backup` o cron con `tools/respaldo.sh respaldar`), retención 14 días, copia semanal fuera
del servidor (almacenamiento de objetos). **RPO de 24 h y RTO de 15 min.** Restauración probada: 1 s en el hosting (2026-11-23);
en CI, en cada ejecución. Procedimiento: `sh tools/respaldo.sh restaurar ARCHIVO` o `gunzip < ARCHIVO | mariadb ...`.

## 7. Monitoreo y logs

| Qué | Dónde | Alerta |
|---|---|---|
| `GET /api/v1/health` (BD y migraciones) | Health check de compose + monitor externo (UptimeRobot, cada 5 min) | Caída > 2 chequeos → correo/Telegram |
| Errores 5xx con `request_id` | `storage/logs/api.log` + `docker compose logs app` | > 5 errores en 10 min |
| 429 (límite de tasa) y 401 repetidos | `rate_limits` / logs de nginx | Picos inusuales (posible fuerza bruta) |
| Uso y fallas de IA | Tabla `ia_consultas` (latencia, éxito) | Tasa de error > 20 % |
| Respaldos | Salida del servicio `backup` | Falta el archivo del día |
| Auditoría de negocio | Tabla `auditoria` (panel **Auditoría**) | Revisión semanal |

## 8. Seguridad en producción

* HTTPS obligatorio (HSTS en el proxy), cookie de sesión `secure`, `httponly`, `samesite=Lax`.
* Cabeceras: `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`; en la API además
  `Content-Security-Policy: default-src 'none'` y `Cache-Control: no-store`.
* CORS cerrado por defecto (`CORS_ORIGENES`). Límite de tasa en login, API e IA.
* Endurecimiento: contenedor `app` con usuario `www-data`, `expose_php=Off`, `display_errors=Off`, base de datos sin
  puerto publicado, archivos internos (`vendor`, `src`, `.env`, `storage`…) bloqueados en nginx y `.htaccess`.
* Imágenes con versión fija; actualización mensual de bases (`php:8.3.x-fpm-alpine`, `nginx`, `mariadb`) y `composer audit`.

## 9. Costos y riesgos

| Ítem | Costo mensual estimado (CLP) |
|---|---|
| VPS 2 vCPU / 4 GB | 9.000 a 12.000 |
| Dominio `.cl` (anual prorrateado) | 800 |
| Respaldo externo (objetos, 10 GB) | 1.000 |
| API de IA (Groq; nivel gratuito suficiente para ~100 resúmenes/día) | 0 a 5.000 |
| **Total** | **≈ 11.000 a 19.000** |

| Riesgo | Mitigación |
|---|---|
| Pérdida de datos | Respaldo diario + externo, restauración probada. |
| Exposición de secretos | `.env` fuera del repo, rotación de JWT y clave IA, CI enmascara. |
| Caída del proveedor de IA | El resto del sistema sigue funcionando (502/503/504 controlados). |
| Emisión tributaria accidental | Modo demo fijo en el código: el cliente del SII lanza excepción. |
| Datos personales en la demo | Solo datos sintéticos; la IA recibe agregados. |
| Saturación por abuso | Límites de tasa y topes de exportación. |

## 10. Lista de verificación previa a producción

- [ ] `.env` completo, permisos 600, `SEED_DEMO=0`, `APP_ENV=production`.
- [ ] `JWT_SECRET` nuevo (≥ 32 caracteres) y `GROQ_API_KEY` vigente.
- [ ] Pipeline en verde (pruebas, contenedores, humo, respaldo/restauración, rollback).
- [ ] Respaldo previo tomado y restauración verificada.
- [ ] HTTPS activo y redirección 80 → 443.
- [ ] `/api/v1/health` responde `ok` y la prueba de humo pasa.
- [ ] Cuentas demo deshabilitadas; contraseñas propias para cada rol.
- [ ] Monitor externo y alertas configurados.
- [ ] Auditoría Lighthouse/axe sin errores críticos en las pantallas principales.
- [ ] Revisión de cabeceras de seguridad (securityheaders.com) y de CORS.

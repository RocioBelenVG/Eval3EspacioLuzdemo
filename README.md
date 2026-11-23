# Espacio Luz Demo

Sistema web de gestión de un **centro terapéutico** (terapia ocupacional, fonoaudiología, psicología) que además
**arrienda boxes por hora** a profesionales externos: sitio público con agenda en línea, agenda de atenciones con flujo de
estados, fichas clínicas, cobros con boletas y facturas, dashboard de indicadores, reportes exportables, resumen con IA y
API REST documentada.

> **Demo académica de la Evaluación 3 de Lenguaje de Programación Web II.** Es una copia del sistema EspacioLuz en producción con
> **datos 100 % sintéticos** (ningún paciente, profesional ni cliente es real) y documentos tributarios **de demostración**:
> las boletas y facturas usan folios de un CAF de prueba, muestran la leyenda «sin validez tributaria» y **nunca se envían al
> SII** (el cliente del SII está bloqueado en el código, `dte/lib/dte_demo.php`). Los correos se guardan como `.eml` y no se envían.

* **En línea:** https://trivoxa.cl/espacioluzdemo, panel: `/admin/login`, API: `/api/v1`, Swagger: `/api/docs`
* **Contrato OpenAPI:** [`docs/openapi.yaml`](docs/openapi.yaml)

## Ejecutar con un solo comando

Requisitos: Docker 24+ con Compose v2.

```bash
cp .env.example .env          # completar DB_PASS, DB_ROOT_PASSWORD, JWT_SECRET (openssl rand -hex 32) y DEMO_PASSWORD
docker compose up             # → http://localhost:8080
```

`docker compose up` levanta 5 servicios: **web** (nginx, puerto 8080), **app** (PHP-FPM 8.3: panel, sitio y API),
**db** (MariaDB 11.8, red interna), **migrate** (aplica las 6 migraciones y, si la base está vacía, genera 12 meses de datos
sintéticos y las cuentas demo; luego termina) y **backup** (respaldo diario con retención de 14 días).

### Cuentas de demostración

| Rol | Correo | Ve |
|---|---|---|
| Administración | `admin@espacioluzdemo.cl` | Todo, incluida la auditoría |
| Recepción | `recepcion@espacioluzdemo.cl` | Agenda, pacientes, cobros, reporte operativo |
| Área clínica | `clinica@espacioluzdemo.cl` | Sus pacientes, fichas y atenciones |
| Gerencia | `gerencia@espacioluzdemo.cl` | Dashboard, indicadores, reportes e IA (solo lectura) |

La contraseña de las cuatro es el valor de `DEMO_PASSWORD` de tu `.env` (en el servidor de demostración, el del `.env` del
hosting; nunca se publica en el repositorio).

### Sin Docker (hosting PHP 8.3 + MariaDB, como el servidor de demostración)

```bash
composer install --no-dev
cp .env.example .env                              # DB_HOST=localhost y credenciales de la base
vendor/bin/phinx migrate -c phinx.php
vendor/bin/phinx seed:run -c phinx.php -s DatosSinteticos   # solo en bases «demo»/«test»
```

Apache/LiteSpeed con `mod_rewrite`: el `.htaccess` enruta todo a `index.php` y bloquea código y secretos.

## Pruebas

```bash
composer install
vendor/bin/phpunit                 # 58 pruebas: unitarias (dominio, JWT, validación, exportadores) + integración de la API
vendor/bin/phpunit --testsuite Unit
```

Las pruebas de integración recorren la API completa contra MariaDB; cada caso corre en una transacción que se revierte y
crea sus propios usuarios con contraseña aleatoria. La IA y el motor DTE se reemplazan por dobles (`tests/Support`).
Último reporte: [`docs/evidencias/pruebas/`](docs/evidencias/pruebas/).

## CI/CD

`.github/workflows/ci.yml`: **pruebas** (PHPUnit contra MariaDB + reversión de migraciones) → **contenedores** (build
multi-etapa con las pruebas unitarias dentro, `docker compose up`, prueba de humo HTTP, respaldo y restauración, rollback)
→ **publicar** (imágenes en GHCR solo en `main` y solo si todo pasó). Los secretos se generan y enmascaran en cada ejecución.

## Estructura

```
index.php            Controlador frontal (sitio, panel y /api/v1)
api/v1/index.php     Entrada de la API REST → src/Kernel.php
src/                 API en capas: Http, Controller, Service, Domain, Repository, Security, Validation, Export, Ia, Dte
lib/ pages/ partials/ api/panel/   Sistema clásico (panel y sitio, renderizado en servidor)
dte/                 Motor de documentos tributarios heredado, en MODO DEMO (dte/lib/dte_demo.php)
assets/css/app.css   Sistema de diseño de los módulos nuevos; assets/js/app/ módulos ES (Atenciones, BI, Reportes)
database/            migrations/ (Phinx V1 a V6), seeds/DatosSinteticos.php, sql/ (esquema base y catálogos)
tests/               Unit/ Integration/ Support/
docker/ Dockerfile compose.yaml .github/   Contenedores y pipeline
tools/               CLI: respaldo, recorrido del panel por rol, indicadores, instantáneas para auditar accesibilidad
docs/                Documentación y evidencias (ver abajo)
```

## Documentación

| Documento | Contenido |
|---|---|
| [01 Requerimientos](docs/01_requerimientos.md) | Roles, RF/RNF priorizados, casos de uso, historias con criterios de aceptación, trazabilidad |
| [02 Modelo de datos](docs/02_modelo_datos.md) | ER, diccionario, normalización, migraciones, vistas, índices, respaldo y restauración |
| [03 Arquitectura](docs/03_arquitectura.md) | Capas, componentes, despliegue, patrones (ADR), SOLID, RNF y OWASP |
| [04 Guía de estilos](docs/04_guia_estilos.md) y [maquetas](docs/maquetas/index.html) | Paleta, tipografía, espaciado, componentes, responsive |
| [05 Plan de despliegue](docs/05_plan_despliegue.md) | Los 10 apartados |
| [06 Manual de usuario](docs/06_manual_usuario.md) | Por rol |
| [07 Indicadores](docs/07_indicadores.md) | 10 indicadores con fórmula e interpretación, dashboard, reportes y formatos |
| [08 Informe de integración](docs/08_informe_integracion.md) | Articulación por asignatura y reflexión |
| [09 Accesibilidad](docs/09_accesibilidad.md) | WCAG 2.2 AA, auditoría axe, pruebas de teclado |
| [OpenAPI](docs/openapi.yaml) | Contrato de la API |

## Uso de herramientas de IA (declaración)

Se usó **Claude (Anthropic) en Claude Code** como asistente de desarrollo para: analizar el sistema EspacioLuz original,
proponer la arquitectura en capas, escribir código de la API, migraciones, datos sintéticos, pruebas, front end,
contenedores y pipeline, ejecutar las pruebas y auditorías, y redactar la documentación. Todo el código y la documentación
fueron revisados por el equipo, que debe poder explicarlos y modificarlos en vivo. El sistema usa además un servicio de IA
en tiempo de ejecución (Groq, modelo GPT-OSS 120B) para el resumen ejecutivo del dashboard, solo con datos agregados.

## Licencia y datos

Uso académico. Imágenes del sitio público: propiedad de Espacio Luz. Datos de la base: sintéticos, generados por
`database/seeds/DatosSinteticos.php`.

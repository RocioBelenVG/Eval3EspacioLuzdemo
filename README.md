# Espacio Luz — versión PHP

Port completo a **PHP puro** (sin framework ni Composer) del sitio original de
Espacio Luz, que estaba hecho en Next.js + React + TypeScript. Reproduce la misma
interfaz, contenidos y funcionalidad, pero corre en cualquier hosting con PHP
(p. ej. Hostinger) sin Node.js.

## Requisitos

- PHP 8.0 o superior.
- Apache con `mod_rewrite` (el `.htaccess` incluido hace el enrutamiento).
  En Nginx hay que enrutar todo a `index.php` a mano.
- Conexión a Internet del visitante (Tailwind, Google Fonts y MapLibre se cargan
  por CDN).

## Cómo correr en local

Con el servidor embebido de PHP, desde esta carpeta (`php/`):

```bash
php -S 127.0.0.1:8000 index.php
```

Abre <http://127.0.0.1:8000>.

## Despliegue en hosting (Apache / Hostinger)

1. Sube **todo el contenido de la carpeta `php/`** a la carpeta pública del
   dominio o subdominio (p. ej. `public_html/` o `.../espacioluz.trivoxa.cl/`).
2. Asegúrate de que exista soporte de `.htaccess` (`AllowOverride All`).
3. Da permiso de **escritura** a la carpeta `content/` y a
   `assets/images/` (para guardar reservas, ediciones e imágenes subidas):

   ```bash
   chmod -R 775 content assets/images
   ```

4. Si el sitio vive en un **subdirectorio** (p. ej. `midominio.cl/espacioluz`),
   normalmente se autodetecta. Si algo no cuadra, fija la base manualmente
   añadiendo en el `.htaccess`:

   ```apache
   SetEnv ESPACIOLUZ_BASE /espacioluz
   ```

## Cuentas de administración

Las cuentas y sus contraseñas (bcrypt) son las mismas del sistema original y se
definen en `config.php` → `ADMIN_ACCOUNTS`:

- `admin@espacioluzdemo.cl`
- `recepcion@espacioluzdemo.cl`

Para cambiar o agregar una contraseña, genera el hash con:

```bash
php -r "echo password_hash('TU_CLAVE', PASSWORD_BCRYPT), PHP_EOL;"
```

y reemplaza el `passwordHash` correspondiente.

Inicia sesión en `/admin/login`. Con la sesión abierta aparece el botón
flotante **“Editar contenidos”**: al activarlo puedes editar textos en línea y
cambiar/añadir imágenes. Los cambios se guardan en `content/overrides.json`
(textos) y en `assets/images/` (imágenes), igual que en el sistema original.

## Estructura

```
index.php            Controlador frontal (router)
config.php           Configuración, base URL, cuentas admin, sesión
data.php             Datos base (centro, especialidades, profesionales, boxes)
lib/                 Lógica: content, reservas, auth, images, helpers
pages/               Páginas: home, box, login, 404
partials/            Vistas: head/foot, header, footer y secciones del home
api/                 Endpoints JSON: reservas, content, subida de imágenes
content/             Datos en JSON: overrides.json (ediciones) y reservas.json
assets/              images/, map-style.json y js/main.js (interactividad)
```

## Equivalencias con el original (Next.js → PHP)

| Original (Next.js)                         | Aquí (PHP)                                    |
|--------------------------------------------|-----------------------------------------------|
| App Router (`app/`)                        | `index.php` (router) + `pages/`               |
| Componentes React server                   | Parciales PHP (`partials/`)                    |
| Componentes `"use client"`                 | `assets/js/main.js` (JS vanilla)              |
| NextAuth + bcrypt                          | Sesiones PHP + `password_verify` (`lib/auth`) |
| Rutas `app/api/*`                          | `api/*.php`                                    |
| `lib/data.ts`, `lib/content.ts`, etc.      | `data.php`, `lib/content.php`, etc.           |
| Tailwind (build)                           | Tailwind vía CDN con el mismo tema            |
| Embla Carousel (boxes)                     | Carrusel scroll-snap nativo                   |
| DepthCarousel (GSAP) en detalle de box     | Galería con lightbox                          |
| LightRays (WebGL/OGL) en el hero           | Aproximación con gradiente cálido             |
| MapLibre                                   | MapLibre (CDN), mismo `map-style.json`        |

### Notas

- **Tailwind por CDN**: mantiene idénticas todas las clases sin paso de build.
  Para producción de alto tráfico se puede compilar un CSS estático más adelante.
- Los efectos decorativos pesados (LightRays WebGL y el carrusel 3D con GSAP) se
  reemplazaron por equivalentes livianos sin dependencias, conservando el look.
- El almacenamiento sigue siendo por archivos JSON (como el original). Si más
  adelante quieres migrar a MySQL, la capa de datos está aislada en `lib/`.

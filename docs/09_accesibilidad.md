# 09 · Accesibilidad (WCAG 2.2 nivel AA)

## 1. Alcance

* **Pantallas nuevas** (Atenciones, Dashboard, Reportes, Auditoría, 403, Swagger): diseñadas desde cero con el sistema de
  diseño accesible (`assets/css/app.css`, `assets/js/app/nucleo.js`).
* **Panel clásico heredado** (≈ 30 pantallas): mejoras transversales aplicadas en la plantilla común —enlace «Saltar al
  contenido», `<main id="contenido">`, foco visible de 3 px en todo el panel, menú móvil con `aria-expanded`/`aria-controls`
  y cierre con Esc, desplegable personalizado con nombre accesible y teclado, títulos de página descriptivos y contraste
  del encabezado—. Las pantallas heredadas con formularios propios no se reescribieron; queda registrado como limitación.

## 2. Lista de verificación

| Criterio WCAG 2.2 | Cómo se cumple | Verificado |
|---|---|---|
| 1.1.1 Contenido no textual | Logo con `alt`; íconos decorativos `aria-hidden`; gráficos con `role="img"`, título y descripción. | axe |
| 1.3.1 Información y relaciones | Regiones (`header`, `nav`, `main`), encabezados jerárquicos (h1 título de pantalla, h2 secciones, h3 tarjetas), tablas con `caption`, `th scope="col"` y encabezados de fila, `label for`, `fieldset/legend`. | axe |
| 1.3.4 / 1.4.10 Orientación y reajuste | Mobile first sin desplazamiento horizontal de 360 a 1440 px; tablas como tarjetas en celular. | script responsive |
| 1.4.1 Uso del color | Estados con texto + ícono (✓ ✕ !), variaciones con ▲/▼ y texto, errores con ⚠ y texto, mapa de calor con números. | revisión |
| 1.4.3 / 1.4.11 Contraste | Texto ≥ 4,5:1 (café 13,5:1; terracota de botón 5,5:1), bordes de campos 4,5:1, foco 6,1:1. | axe + cálculo (`04_guia_estilos.md`) |
| 1.4.4 Zoom 200 % | Unidades `rem`, rejillas `minmax(0,1fr)`, sin alturas fijas en texto. Un escritorio de 1280 px al 200 % equivale a 640 px de ancho, cubierto por las pruebas a 360 y 768 px. | script responsive (equivalencia) |
| 1.4.12 / 1.4.13 | Interlineado ≥ 1,25; ayudas en `<details>` (no tooltips flotantes). | revisión |
| 2.1.1 / 2.1.2 Teclado sin trampas | Todo operable con teclado; diálogos `<dialog>` nativos (foco atrapado, Esc cierra, foco devuelto); pestañas con flechas; desplegable personalizado con flechas/Enter/Esc/Tab. | prueba con teclado (§3) |
| 2.4.1 Saltar bloques | «Saltar al contenido» como primer elemento. | prueba con teclado |
| 2.4.2 Título de página | «Pantalla — Panel · Espacio Luz Demo». | revisión |
| 2.4.3 Orden del foco | Orden del DOM = orden visual; al abrir un diálogo el foco entra y al cerrar vuelve al botón. | prueba con teclado |
| 2.4.7 / 2.4.11 Foco visible y no oculto | Anillo azul de 3 px en todo el panel; encabezado de tabla oculto en celular con `display:none` (sin focos invisibles). | prueba + script |
| 2.5.8 Tamaño del objetivo | Controles ≥ 44 px (mínimo 24 px; botones chicos 36 px; botones de orden 32 px). | script responsive |
| 3.1.1 Idioma | `<html lang="es">`. | axe |
| 3.3.1 / 3.3.3 Identificación y sugerencia de errores | Validación inmediata al salir del campo; mensaje específico con la corrección; resumen enfocable con enlaces a cada campo; errores de la API (422) mapeados al campo. | prueba (captura `validacion_inmediata_1440px.jpg`) |
| 3.3.2 Etiquetas o instrucciones | Etiqueta visible en cada campo; obligatorios marcados (* + texto para lectores). | axe |
| 4.1.2 Nombre, función y valor | Botones con `aria-label` contextual («Gestionar atención del 12-12-2026 19:00»), `aria-sort`, `aria-selected`, `aria-expanded`, `aria-invalid`, `aria-describedby`. | axe |
| 4.1.3 Mensajes de estado | Región `aria-live` para resultados, cargas y avisos (`anunciar()`); errores con `role="alert"`. | revisión |
| `prefers-reduced-motion` | Animaciones y transiciones anuladas; gráficos sin animación. | revisión |

## 3. Pruebas realizadas

**Automática (axe-core 4.9.1, 2026-11-23):** 0 violaciones en las 5 pantallas nuevas tras corregir las 3 reglas de la
primera pasada. Detalle: `docs/evidencias/accesibilidad/auditoria_axe_2026-11-23.md`.

**Teclado (2026-11-23, navegador integrado, pantalla Atenciones, 1440 px):**
1. *Tab* desde el inicio → primer foco en «Saltar al contenido» (visible); *Enter* → el foco pasa a `#contenido`.
2. *Tab* hasta «Gestionar» de la primera fila → nombre anunciado «Gestionar atención del 12-12-2026 19:00».
3. *Enter* → se abre el diálogo «Atención del 12-12-2026 · 19:00»; el foco queda dentro; las opciones «Realizada» y
   «No asistió» aparecen deshabilitadas con la explicación (atención futura).
4. *Esc* → el diálogo se cierra y el foco vuelve al mismo botón «Gestionar».
5. «Nueva atención» con hora 10:15 → al salir del campo aparece «Usa bloques de 30 minutos…» y el campo queda
   `aria-invalid`; al enviar, el resumen de 3 errores recibe el foco.

**Lector de pantalla — protocolo para NVDA 2024 + Firefox/Chrome (a ejecutar por el equipo antes de la defensa):**
recorrer con *H* los encabezados del Dashboard, *T* hasta la tabla «Ver tabla de datos» de un gráfico, *Ctrl+Alt+flechas*
por sus celdas; en Atenciones, crear una atención con un error y comprobar que NVDA lee el resumen y el error del campo;
abrir y cerrar el diálogo verificando el anuncio del título; en Reportes, cambiar de pestaña con flechas y exportar
oyendo «Descargado …». Registrar fecha, versión y resultado en esta sección. *(No se pudo ejecutar NVDA en el entorno de
desarrollo automatizado; queda como tarea del equipo y se declara en «Limitaciones» del informe.)*

## 4. Capturas responsive

`docs/evidencias/capturas/`: Atenciones en 360 px (filtros y tarjetas apiladas), Dashboard en 768 px (gráficos a dos
columnas), Reportes y reporte gerencial en 1440 px, diálogo de estado y validación inmediata en 1440 px.

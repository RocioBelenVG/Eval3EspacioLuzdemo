# Guía de estilos y maquetas

El sistema de diseño de los módulos nuevos (`assets/css/app.css`) extiende la identidad visual de Espacio Luz (crema,
café, terracota y salvia del sitio público) con tokens accesibles. El panel clásico usa Tailwind por CDN **personalizado**
con la misma paleta (`partials/panel/top.php`), así que ambas superficies se ven como un solo producto.

Maquetas navegables por rol: `docs/maquetas/index.html` (abrir en el navegador; no requiere servidor).

## 1. Paleta (contraste verificado WCAG 2.2 AA)

| Token | Color | Uso | Contraste |
|---|---|---|---|
| `--c-texto` | `#3D2A21` café oscuro | Texto principal | 13,5:1 sobre blanco y 12,9:1 sobre `#FBF9F4` |
| `--c-texto-suave` | `#6B5446` | Texto secundario y ayudas | 7,0:1 / 6,7:1 |
| `--c-primario` | `#A4512F` terracota accesible | Botón principal (texto blanco) | 5,5:1 |
| `--c-primario-hover` | `#8A4126` | Estado hover/activo del botón | 7,3:1 |
| `--c-secundario` | `#4F6354` salvia oscura | Acentos | 6,5:1 |
| `--c-exito` `--c-error` `--c-info` `--c-aviso` | `#2F6B3F` `#B3261E` `#2D5B86` `#8A5A00` | Estados y mensajes | 6,4:1; 6,5:1; 7,1:1 y 5,9:1 |
| `--c-foco` | `#1F5FBF` | Anillo de foco de 3 px | 6,1:1 (≥ 3:1 exigido) |
| Borde de campos | `#857362` | Contorno de inputs (componente) | 4,5:1 (≥ 3:1 exigido) |
| Fondo | `#FBF9F4` crema; tarjetas `#FFFFFF` | | |

**Nunca solo color:** los estados de una atención usan color **+ ícono/texto** (✓ Realizada, ✕ Cancelada, ! No asistió),
la variación de un indicador usa ▲/▼ y texto «mejora/empeora» para lectores de pantalla, y los errores llevan «⚠» y texto.

## 2. Tipografía

* Títulos: **Fraunces** (serif, la del sitio), variable `--f-titulo`; cuerpo e interfaz: **Inter**, variable `--f-cuerpo`.
* Escala en `rem` (respeta el zoom del navegador): 0,75; 0,875; 1; 1,25 y 1,6 rem. Interlineado 1,25 a 1,5.
* Números tabulares (`font-variant-numeric: tabular-nums`) en columnas de montos.

## 3. Espaciado y forma

Escala de 4 px: `--e-1` 0,25 rem; `--e-2` 0,5; `--e-3` 0,75; `--e-4` 1; `--e-5` 1,5; `--e-6` 2 rem.
Radio 0,75 rem (tarjetas, campos) y píldora (botones). Sombra única `--sombra`. Objetivo táctil `--toque: 44px`.

## 4. Componentes reutilizables y sus estados

| Componente | Clase | Estados visibles |
|---|---|---|
| Botón principal / secundario / peligro / chico | `.el-btn`, `--sec`, `--peligro`, `--chico` | normal, hover (más oscuro), foco (anillo azul 3 px), activo (desplazamiento 1 px), deshabilitado (55 % de opacidad, cursor no permitido), ocupado («Generando…») |
| Campo de formulario | `.el-campo` + `.el-input` + `.el-error` | normal, hover, foco, error (`aria-invalid="true"`, borde rojo y mensaje enlazado con `aria-describedby`), deshabilitado |
| Resumen de errores | `.el-resumen-error` | recibe el foco y enlaza a cada campo con error |
| Tarjeta | `.el-tarjeta` | No aplica |
| Indicador (KPI) | `.el-kpi` | valor, variación mejor/peor, fórmula desplegable |
| Etiqueta de estado | `.el-chip[data-estado]` | 5 estados del flujo |
| Tabla adaptable | `.el-tabla` + `.el-tabla--apilable` | ordenable (`aria-sort`), fila de totales, tarjetas apiladas en celular |
| Paginación | `.el-paginacion` | anterior/siguiente deshabilitados en los extremos |
| Carga / vacío / error | `.el-esqueleto`, `.el-vacio`, `.el-fallo` | esqueleto animado (se detiene con movimiento reducido), mensaje con ícono, error con código de soporte y «Reintentar» |
| Diálogo | `.el-dialogo` (`<dialog>` nativo) | foco inicial, foco atrapado, cierre con Esc y devolución del foco |
| Pestañas | `.el-pestanas [role=tab]` | seleccionada (borde inferior), foco; flechas, Inicio y Fin |
| Aviso de demo | `.el-aviso-demo`, `.pnl-demo` | Recuerda que los documentos no tienen validez tributaria |

## 5. Diseño responsive (mobile first)

| Ancho | Comportamiento |
|---|---|
| 360 a 767 px | Menú lateral colapsable (botón con `aria-expanded`, cierre con Esc); filtros en una columna; **tablas como tarjetas apiladas** (cada celda muestra su etiqueta); gráficos de 280 px de alto; KPI en una columna. |
| 768 a 1023 px | Gráficos en 2 columnas; tablas con desplazamiento interno (región enfocable con nombre). |
| ≥ 1024 px | Menú fijo de 256 px; mapa de calor a dos columnas; hasta 3 columnas en rejillas. |

Sin desplazamiento horizontal de la página: los contenedores usan `min-width: 0`, `minmax(0, 1fr)` y `overflow-x: auto`
solo dentro de las tablas.

## 6. Impresión

`@media print`: A4 horizontal, se ocultan menú, encabezado, botones, filtros y paginación; aparece un encabezado propio con
título del reporte, fecha de emisión, usuario y filtros; los encabezados de tabla se repiten en cada página.

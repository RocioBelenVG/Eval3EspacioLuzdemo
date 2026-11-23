# Auditoría automática de accesibilidad · axe-core 4.9.1 · 2026-11-23

**Herramienta:** axe-core 4.9.1 (Deque), reglas `wcag2a`, `wcag2aa`, `wcag21a`, `wcag21aa`, `wcag22aa` y `best-practice`.
**Cómo:** cada pantalla se renderizó en el servidor con una sesión simulada (sin contraseña) y sus respuestas reales de la
API se capturaron con `tools/instantanea.php`; luego se sirvieron en `http://127.0.0.1:8765` (vista previa local) y axe se
ejecutó en el navegador integrado con la página completamente cargada (gráficos y tablas incluidos).

## Primera pasada (antes de corregir) — Dashboard
| Regla | Impacto | Nodos | Corrección aplicada |
|---|---|---|---|
| `aria-input-field-name` | serio | 4 | El desplegable personalizado del panel (`partials/ez-dialog.php`) no tenía nombre: ahora toma la etiqueta del `<select>`, expone `aria-haspopup`, `aria-expanded`, `aria-controls`, `aria-selected`, `aria-activedescendant` y navegación con flechas, Inicio/Fin, Enter, Esc y Tab. En los módulos nuevos se usa el `<select>` nativo. |
| `color-contrast` | serio | 41 | Mapa de calor con texto oscuro y fondo acotado (≥ 6,3:1); iniciales del avatar en café oscuro; rol del usuario al 90 %. |
| `region` | moderado | 1 | El aviso «Espacio Luz Demo» pasó dentro de `<main>`. |

## Resultado final
| Pantalla | Violaciones | Reglas que pasan |
|---|---|---|
| Atenciones (`/admin/atenciones`) | **0** | 50 |
| Dashboard e indicadores (`/admin/bi`) | **0** | 45 |
| Reportes (`/admin/reportes`) | **0** | 51 |
| Auditoría (`/admin/auditoria`) | **0** | 44 |
| Sin permisos 403 (`/admin/sin-permiso`) | **0** | 40 |

## Verificación responsive automatizada (mismo día)
Script en el navegador que carga cada pantalla en iframes de 360, 768 y 1440 px y mide: desplazamiento horizontal del
documento, elementos de `<main>` que se salen del ancho (fuera de las tablas con desplazamiento interno) y controles
interactivos menores a 24 × 24 px.

| Pantalla | 360 px | 768 px | 1440 px |
|---|---|---|---|
| Atenciones | sin desborde · 0 controles < 24 px | ídem | ídem |
| Dashboard | ídem | ídem | ídem |
| Reportes | ídem | ídem | ídem |
| Auditoría | ídem | — | ídem |
| 403 | ídem | — | — |

Hallazgo corregido en esta pasada: los botones de orden de las columnas medían 18 px de alto (ahora 32 px) y, en celular,
el encabezado oculto los dejaba enfocables sin verse (ahora `display: none` en celular y selector visible «Ordenar por»).

Capturas: `docs/evidencias/capturas/`.

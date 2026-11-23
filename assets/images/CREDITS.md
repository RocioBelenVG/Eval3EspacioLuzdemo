# Créditos de imágenes — Espacio Luz

Todas las imágenes son de uso libre / gratuito para uso comercial, obtenidas de Unsplash, Pexels o Wikimedia Commons. Ninguna imagen de este proyecto proviene del ilustrador Daniel Martínez-Piña ni de ningún otro material con derechos de autor de terceros sin licencia adecuada.

| # | Archivo | Sitio | Autor / Fotógrafo | Licencia | URL de la página fuente |
|---|---------|-------|--------------------|----------|--------------------------|
| 1 | `hero/hero-bg.jpg` | Unsplash | Simona Sergi (@i_am_simoesse) | Unsplash License (gratuita, uso comercial permitido) | https://unsplash.com/photos/cozy-living-room-with-natural-elements-and-warm-tones-8fF_tF2ZMSQ |
| 5 | `boxes/loica.jpg` | Pexels | Max Vakhtbovych (@artbovich) | Pexels License (gratuita, uso comercial permitido) | https://www.pexels.com/photo/living-room-with-cozy-chairs-near-lamp-and-wooden-bookcase-7195289/ |
| 6 | `boxes/martin-pescador.jpg` | **Foto real del centro** (Diego, carpeta `FotosEspacioLuz/Martin1.jpg`) | — | Propiedad de Espacio Luz | — |
| 7 | `boxes/7-colores.jpg` | Pexels | kall | Pexels License (gratuita, uso comercial permitido) | https://www.pexels.com/photo/colorful-kids-playroom-with-bright-decor-29752372/ |
| 8 | `boxes/colibri-gimnasio.jpg` | **Foto real del centro** (Diego, carpeta `FotosEspacioLuz/Colibri1.jpg`) | — | Propiedad de Espacio Luz | — |
| 9 | `birds/loica.png` | **Ilustración generada por IA** (encargada por Diego) | — | Generada para este proyecto, sin base en obra de terceros | — |
| 10 | `birds/martin-pescador.png` | **Ilustración generada por IA** (encargada por Diego) | — | Generada para este proyecto, sin base en obra de terceros | — |
| 11 | `birds/colibri.png` | **Ilustración generada por IA** (encargada por Diego) | — | Generada para este proyecto, sin base en obra de terceros | — |
| 12 | `birds/7-colores.png` | **Ilustración generada por IA** (encargada por Diego) | — | Generada para este proyecto, sin base en obra de terceros | — |

## Notas y sustituciones

- **`boxes/martin-pescador.jpg`** y **`boxes/colibri-gimnasio.jpg`** fueron reemplazadas por fotos reales del centro (carpeta `FotosEspacioLuz` en el Escritorio del cliente) en cuanto se confirmó que eran fotos propias y utilizables. `loica.jpg` y `7-colores.jpg` siguen siendo stock de Pexels porque aún no hay fotos reales de esos dos boxes — reemplazar cuando existan.

- **`birds/*.png`**: se descartó usar fotos reales de aves para el efecto difuminado sobre las tarjetas de boxes (quedaban con fondo rectangular y requerían una máscara CSS agresiva que se veía forzada). Se reemplazaron por 4 ilustraciones en acuarela generadas por IA, con transparencia real (PNG con canal alfa), a partir de prompts detallados por especie (ver historial de la conversación) — sin basarse en el estilo ni la obra de ningún ilustrador de terceros (en particular, no en el trabajo de Daniel Martínez-Piña, cuyas ilustraciones se descartaron por falta de derechos de uso). El componente `Boxes.tsx` las ancla por el borde inferior (`bottom-full`) para que cada ave "aterrice" justo en el borde superior de su tarjeta.
- **`boxes/martin-pescador.jpg`** y **`boxes/colibri-gimnasio.jpg`**: las primeras candidatas descargadas resultaron ser de orientación vertical (a pesar de que las herramientas de búsqueda las describían como horizontales). Se verificaron las dimensiones reales de las 12 imágenes con PowerShell/System.Drawing y se reemplazaron ambas por fotos genuinamente horizontales antes de la entrega final.
- **`boxes/7-colores.jpg`**: la primera candidata era una sala infantil dominada por un solo tono azul oscuro, poco "luminosa/colorida". Se reemplazó por una sala blanca con mural de soles/arcoíris y piso de goma multicolor, más fiel al brief.
- **Profesionales**: se descartaron las fotos de stock de personas reales bajo nombres placeholder (ej. "María González") porque el sitio es para un centro real y podría interpretarse como hacer pasar a un desconocido por parte del equipo. Se reemplazaron por avatares con iniciales en degradé de marca (ver `components/sections/Profesionales.tsx`), fáciles de cambiar por fotos reales del equipo cuando existan.
- Todas las imágenes de `boxes/` y `birds/` fueron redimensionadas a un máximo de ~1600–1800px en el lado largo (desde el archivo original) para mantener un peso razonable; el hero y las fotos de profesionales se descargaron ya redimensionadas vía parámetros de URL de Unsplash/Pexels.

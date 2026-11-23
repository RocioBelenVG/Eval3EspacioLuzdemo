# 07 · Análisis de datos: indicadores, dashboard y reportes

Todos los indicadores se calculan **desde los datos del sistema** con `DashboardService` (la misma lógica que consumen el
dashboard, el reporte gerencial y la IA), sobre las vistas `v_atenciones`, `v_documentos` y `v_ingresos_diarios`.
Valores de esta sección: semestre **24-05-2026 a 22-11-2026** comparado con el semestre anterior (22-11-2025 a 23-05-2026),
exportados en `docs/evidencias/indicadores_semestre.json` (y 12 meses en `indicadores_12_meses.json`). Los datos son
sintéticos pero generados con reglas verosímiles (estacionalidad, alta de pacientes, comportamiento de pago).

## 1. Indicadores

| # | Indicador | Pregunta de negocio | Fórmula | Semestre actual | Semestre anterior | Lectura |
|---|---|---|---|---|---|---|
| 1 | Tasa de asistencia | ¿Los pacientes llegan a las sesiones que agendan? | sesiones realizadas ÷ (realizadas + inasistencias) × 100 | **92,8 %** | 93,6 % | Leve baja (−0,8 pp). Alta en términos absolutos, pero cada punto equivale a ~17 sesiones por semestre sin cobro (≈ $0,6 M). |
| 2 | Tasa de cancelación | ¿Qué parte de la agenda se pierde por cancelaciones? | canceladas ÷ agendadas × 100 | **9,3 %** | 8,8 % | Sube medio punto; se concentra en meses de invierno (licencias por enfermedad). |
| 3 | Ocupación de boxes | ¿Aprovechamos la capacidad instalada? | horas-box con reserva vigente ÷ (horas de apertura × días hábiles × boxes) × 100 | **26,9 %** | 21,1 % | Mejora 5,8 pp, pero el promedio esconde una gran dispersión entre boxes (ver §2). |
| 4 | Ingresos netos | ¿Cuánto factura el centro? | Σ boletas y facturas emitidas − Σ NC + Σ ND | **$53.483.500** | $41.127.500 | +30 %: más pacientes nuevos y mayor arriendo de boxes. |
| 5 | Ticket promedio por sesión | ¿Cuánto ingresa cada sesión cobrada? | ingresos de boletas a pacientes ÷ sesiones cobradas | **$33.088** | $33.253 | Estable; el crecimiento de ingresos viene del volumen, no del precio. |
| 6 | Pacientes nuevos | ¿Cuántos pacientes se suman? | pacientes con primera atención realizada en el período | **280** | 179 | +56 %: la captación funciona. |
| 7 | Retención de pacientes | ¿Los pacientes continúan su tratamiento? | atendidos en el período anterior que vuelven ÷ atendidos en el período anterior × 100 | **65,4 %** | — | Dos de cada tres continúan; el resto corresponde mayoritariamente a altas terapéuticas (220 de 459 fichas cerradas). |
| 8 | Días promedio de pago de facturas | ¿Cuánto demoran los clientes en pagar el arriendo? | promedio (fecha de pago − fecha de emisión) de facturas pagadas | **22,8 días** | 25,2 días | Mejora 2,4 días, dentro del plazo de 30 días. |
| 9 | Cuentas por cobrar | ¿Cuánto dinero facturado falta recaudar? | Σ saldos de documentos emitidos no pagados al cierre | **$3.128.000** | $910.000 | Se triplica: hay facturas de un cliente que paga sistemáticamente tarde y boletas recientes por cobrar. |
| 10 | Anticipación de reserva | ¿Con cuántos días de anticipación se agenda? | promedio (fecha de la atención − fecha de creación) | **13,1 días** | 12,2 días | Permite planificar la dotación con dos semanas de anticipación. |

## 2. Tendencias y comparaciones

* **Estacionalidad:** las atenciones realizadas caen en febrero (189) y julio (320) —vacaciones— y alcanzan su máximo en
  octubre (473) y mayo–junio (445–448). Los ingresos siguen la misma curva: de $4,4 M en febrero a $10,2 M en octubre.
* **Ocupación por box (semestre):** Loica 41,0 % y Gimnasio Colibrí 38,7 % (sesiones de las socias) frente a
  Martín Pescador 31,1 %, **Gimnasio Golondrina 12,6 %** y **7 Colores 10,9 %** (solo arriendo externo).
* **Especialidades:** Terapia Ocupacional (956 h) y Fonoaudiología (788 h) generan el 68 % de las horas de box.
* **Demanda por día y hora:** el martes es el día de mayor demanda (526 reservas en el semestre) y el lunes el menor (360);
  las horas pico son 16:00–17:00 y las de menor uso, 08:00 y 19:00.

## 3. Recomendaciones de negocio

1. **Comercializar los boxes subutilizados** (7 Colores y Golondrina, < 13 % de ocupación): ofrecer planes mensuales de
   media jornada a profesionales externos los lunes y en los bloques de 08:00 y 19:00. Pasar ambos boxes al 25 % sumaría
   ~510 horas por semestre (≈ $4 a $6 M a $8.000–12.000 la hora).
2. **Reducir inasistencias:** recordatorio el día anterior para las sesiones agendadas con más de 7 días de anticipación
   (la anticipación media es de 13 días). Recuperar 1 punto de asistencia equivale a ≈ $0,6 M al semestre.
3. **Gestionar la cobranza:** el saldo por cobrar se triplicó; aplicar recordatorio a los 25 días de emitida la factura y
   revisar las condiciones del cliente con pagos sobre 30 días.
4. **Planificar la dotación estacional:** reforzar marzo–junio y octubre; usar febrero y julio para formación y
   mantención de boxes.

## 4. Dashboard (BI II)

`/admin/bi` (Administración y Gerencia). Alimentado por `GET /api/v1/dashboard/indicadores` y `/dashboard/series`;
se actualiza al cambiar filtros y cada 5 minutos, de modo que refleja cualquier cambio en los datos del sistema.

| # | Visualización | Tipo de dato → gráfico | Filtros |
|---|---|---|---|
| 1 | Atenciones por mes según resultado | Serie temporal categórica → barras apiladas | Fecha, especialidad, box, profesional |
| 2 | Ingresos netos por mes | Serie temporal continua → línea con área | Fecha |
| 3 | Horas de box por especialidad | Comparación de categorías → barras horizontales ordenadas | Todos |
| 4 | Ocupación por box | Porcentaje por categoría → barras horizontales 0–100 % | Todos |
| 5 | Distribución por estado | Partes de un todo (5 categorías) → anillo | Todos |
| 6 | Demanda por día y hora | Matriz de dos dimensiones → mapa de calor con números | Todos |

Cada gráfico tiene título, descripción textual (`aria-describedby`) y una **tabla de datos** desplegable; los colores son
aptos para daltonismo y el mapa de calor muestra el número en cada celda. La sección «Lectura del período» genera una
narrativa con el mes más alto y más bajo, el box menos ocupado, la asistencia y la cobranza.

## 5. Reportes y formatos de exportación

| Reporte | Tipo | Roles | Filtros | Contenido |
|---|---|---|---|---|
| Atenciones | Operativo (detalle) | Admin, Recepción, Gerencia (sin nombres de pacientes) | Rango, estado, profesional, box, especialidad, uso | Fecha, hora, box, profesional, especialidad, paciente/uso, tipo, estado, precio, cobro; total de precio. |
| Gerencial mensual | Gerencial (resumen) | Admin, Gerencia | Rango, especialidad, box | Por mes: agendadas, realizadas, canceladas, inasistencias, asistencia, horas de box, pacientes, documentos, ingresos; totales, los 10 indicadores y gráfico de ingresos (en pantalla y en el PDF). |
| Documentos y cobranza | Parametrizable por rango de fechas (obligatorio) | Admin, Gerencia | Rango, tipo, estado de pago | Fecha, documento, folio, receptor, emisor, total, notas de crédito, pagado, saldo, estado de pago, vencimiento, días de pago; totales. |

| Formato | Características |
|---|---|
| **Excel (.xlsx)** | Título, empresa, fecha de emisión y filtros en las primeras filas; encabezados en negrita con fondo, fila fija y autofiltro; fechas reales (dd-mm-aaaa) y números con formato de miles/CLP/%; fila de totales con fórmulas `SUMA`. |
| **PDF** | TCPDF con fuente Unicode (texto seleccionable); en cada página: título, empresa, fecha de emisión, emisor y filtros; encabezados de tabla repetidos; fila de totales; «Página X de Y». |
| **CSV** | UTF-8 con BOM, **separador punto y coma (;)**, coma decimal, fechas AAAA-MM-DD; primera fila de encabezados y última de totales (abre directo en Excel en español de Chile). |
| **Impresión** | Botón «Imprimir»: carga hasta 1.000 filas con los filtros, agrega encabezado con título, fecha, usuario y filtros, y `@media print` oculta menú, botones y filtros. |

Las exportaciones respetan el rol (403 si el reporte no está permitido), aplican exactamente los filtros de la pantalla,
tienen un tope de 20.000 filas y se registran en la tabla `exportaciones` (visible en **Auditoría**). Se generan con
`fetch` en segundo plano: la interfaz sigue usable mientras se descarga el archivo.

# 01 · Requerimientos e historias de usuario

**Sistema:** Espacio Luz Demo — gestión de un centro terapéutico infantil y adulto (Los Ángeles, Región del Biobío) que
atiende pacientes con profesionales socias (terapia ocupacional y fonoaudiología) y además **arrienda boxes por hora** a
profesionales externos (psicología, psiquiatría, psicopedagogía), a quienes factura mensualmente.

**Problema.** La agenda, los cobros y la información clínica estaban repartidos entre planillas y mensajería; no había
indicadores de ocupación, asistencia ni cobranza, y no existía una API para integrar otras herramientas.

**Objetivo.** Ofrecer un sistema web completo —sitio público, agenda, fichas clínicas, cobros con documentos tributarios y
análisis— con una API REST segura, reportes exportables y un dashboard que guíe las decisiones de la administración.

**Alcance de la demo.** Es una copia del sistema EspacioLuz en producción, con **datos 100 % sintéticos** y documentos
tributarios **de demostración** (nunca se envían al SII). Fuera de alcance: emisión tributaria real, pagos en línea,
notificaciones por correo reales (se guardan como `.eml`).

## 1. Roles de usuario

| Rol (clave en BD) | Etiqueta | Qué puede hacer |
|---|---|---|
| `admin` | Administración | Todo: agenda, pacientes, documentos, configuración, usuarios, reportes, dashboard, IA y auditoría. |
| `editor` | Recepción | Agenda (crear, reprogramar, cambiar estado), pacientes, cobros y pagos, reporte operativo. Sin dashboard financiero, auditoría ni configuración. |
| `pacientes` | Área clínica | Solo sus pacientes y fichas clínicas; ve sus propias atenciones y registra si se realizaron o si el paciente no asistió. |
| `gerencia` | Gerencia | Solo lectura: dashboard, indicadores, los tres reportes (con exportación) y resumen con IA. No ve nombres de pacientes. |

La matriz completa de permisos por ruta está en `src/Kernel.php` (API) y `lib/auth.php` (panel).

## 2. Requerimientos funcionales (priorizados con MoSCoW)

| ID | Requerimiento | Prioridad | Verificación |
|---|---|---|---|
| RF01 | Autenticación con JWT y cierre de sesión; la sesión del panel se canjea por un JWT para los módulos nuevos. | Must | `AuthTest` (9 casos) |
| RF02 | Autorización por rol en cada ruta de la API y del panel; el área clínica solo accede a lo propio. | Must | `AtencionesTest::gerencia_lee…`, `PacientesTest::…area_clinica…`, recorrido `tools/recorrer_panel.sh` por rol |
| RF03 | Agendar atenciones (sesión con paciente o arriendo de box) respetando calendario, horario, cupo por box y disponibilidad del profesional. | Must | `AtencionesTest::las_reglas_de_calendario…`, `…box_lleno…` |
| RF04 | Flujo de estados pendiente → confirmada → realizada / no asistió, con cancelación y reactivación; impide transiciones inválidas. | Must | `EstadoReservaTest`, `AtencionesTest::el_flujo…`, disparador `trg_reservas_transicion` |
| RF05 | Registro de pacientes con validación de RUT, consentimiento informado y apoderado para menores. | Must | `PacientesTest` |
| RF06 | Ficha clínica por especialidad con evoluciones, informes y certificados (módulo heredado). | Must | Recorrido del panel (`/admin/pacientes/{id}`) |
| RF07 | Cobro de sesiones realizadas con boleta exenta **de demostración** y registro de pagos con estado de pago recalculado. | Must | `CobrosTest` |
| RF08 | Facturación mensual de arriendos a clientes, notas de crédito y débito (módulo heredado, en modo demo). | Should | Recorrido del panel (`/admin/clientes`, `/admin/documentos`) |
| RF09 | Registro de auditoría de altas, cambios y eliminaciones en las entidades principales (quién, qué, cuándo, desde dónde). | Must | `AnalisisTest::solo_administracion_lee_la_auditoria…`, `AtencionesTest::recepcion_agenda…` |
| RF10 | Dashboard con 10 indicadores, 6 visualizaciones y filtros por fecha, especialidad, box y profesional. | Must | `AnalisisTest::los_indicadores…`, `…series…` |
| RF11 | Tres reportes (operativo, gerencial, documentos por rango) paginados, filtrables, exportables a CSV/XLSX/PDF e imprimibles; registro de quién exporta. | Must | `AnalisisTest::los_tres_formatos…`, `…respeta_los_permisos…` |
| RF12 | Resumen ejecutivo con IA a partir de indicadores agregados. | Should | `AnalisisTest::la_ia…`, `…errores_de_la_ia…` |
| RF13 | Sitio público con boxes, profesionales y agenda en línea (heredado). | Should | Recorrido del panel (`/`, `/boxes/loica`, `/agenda`) |
| RF14 | Documentación interactiva de la API (Swagger) y contrato OpenAPI. | Must | `GET /api/docs`, `GET /api/v1/openapi.yaml` |

## 3. Requerimientos no funcionales (verificables)

| ID | Categoría | Requerimiento | Cómo se atiende / mide |
|---|---|---|---|
| RNF01 | Seguridad | Mitigar al menos 5 riesgos del OWASP API Security Top 10. | API1 BOLA, API2 autenticación, API3 propiedades, API4 consumo, API5 funciones, API8 configuración, API10 consumo de APIs externas (ver `03_arquitectura.md` §8). |
| RNF02 | Seguridad | Ningún secreto en el repositorio ni en su historial. | `.env` excluido, `.env.example` sin valores; CI genera y enmascara secretos. |
| RNF03 | Rendimiento | Listados y reportes paginados responden en < 1 s con 5.500 atenciones. | Índices `idx_res_fecha_prof_estado`, `idx_bol_fecha_tipo_pago`; `per_page` ≤ 100; exportación ≤ 20.000 filas. |
| RNF04 | Disponibilidad | Restaurar la base desde un respaldo en menos de 15 minutos (RTO) con pérdida máxima de 24 h (RPO). | Respaldo diario (servicio `backup`); prueba en hosting: 1 s; prueba automática en CI. |
| RNF05 | Integridad | Validación en tres niveles (formulario, API, base de datos). | 34 restricciones CHECK (31 de la migración V3) y disparadores; `Validator`; validación inmediata en formularios. |
| RNF06 | Accesibilidad | WCAG 2.2 AA en las pantallas nuevas y mejoras globales en el panel. | Ver `09_accesibilidad.md`. |
| RNF07 | Usabilidad móvil | Sin desplazamiento horizontal de 360 a 1440 px; objetivos táctiles ≥ 44 px. | Sistema de diseño mobile first (`assets/css/app.css`). |
| RNF08 | Trazabilidad | Toda respuesta de error trae un `request_id` registrado en `storage/logs/api.log`. | `Kernel::handle`. |
| RNF09 | Portabilidad | El sistema arranca con `docker compose up` sin configuración previa más que el `.env`. | `compose.yaml`, servicio `migrate`; pipeline `contenedores`. |
| RNF10 | Cumplimiento | Datos personales y clínicos mínimos; la IA nunca recibe datos de pacientes (Ley 19.628 y 20.584). | `IaService` solo envía agregados; prueba `la_ia_recibe_solo_agregados…`. |

## 4. Casos de uso completos

| CU | Nombre | Actor | Flujo principal | Alternativos / errores |
|---|---|---|---|---|
| CU-01 | Agendar una atención | Recepción, Administración | Elige profesional, box, fecha y hora (y paciente si es sesión) → el sistema valida calendario, horario, cupo y choque → crea la atención en estado *pendiente* y la audita. | Fecha pasada, domingo o feriado (422); fuera de horario o no múltiplo de 30 min (422); box lleno (409 BOX_SIN_CUPO); profesional ocupado (409). |
| CU-02 | Gestionar el estado de una atención | Recepción, Administración, Área clínica (solo realizada/no asistió en lo propio) | Abre la atención → ve las transiciones permitidas → elige el nuevo estado (y motivo) → se registra el movimiento. | Transición inválida (409 TRANSICION_INVALIDA); marcar realizada una atención futura (422); cancelar una atención ya cobrada (409 COBRO_ASOCIADO). |
| CU-03 | Registrar un paciente | Recepción, Administración | Ingresa datos, RUT y consentimiento → el sistema valida y guarda con RUT formateado. | RUT inválido o repetido (422/409); menor sin apoderado (422); sin consentimiento (422). |
| CU-04 | Registrar la evolución clínica | Área clínica | Abre la ficha del paciente por especialidad → registra la evolución de la sesión → genera el PDF de la ficha. | Paciente de otro profesional: no visible. |
| CU-05 | Cobrar una sesión con boleta demo | Recepción, Administración | En una atención *realizada* sin cobro → elige medio de pago → el motor DTE en modo demo asigna folio de un CAF de prueba → se registra la boleta, el pago y el vínculo con la atención. | Arriendo (409 NO_ES_SESION); no realizada (409); ya cobrada (409 YA_COBRADA); motor DTE falla (502, sin cobro a medias). |
| CU-06 | Registrar el pago de una factura | Recepción, Administración | En un documento con saldo → ingresa monto, fecha y medio → se recalcula el estado de pago. | Monto mayor al saldo o fecha futura (422); documento anulado o pagado (409). |
| CU-07 | Analizar el desempeño y exportar reportes | Gerencia, Administración | Filtra el dashboard por período/especialidad/box → revisa indicadores, gráficos y lectura → abre un reporte, lo exporta a Excel/PDF/CSV o lo imprime. | Rango inválido (422); reporte sin permiso (403); más de 20.000 filas (422 DEMASIADAS_FILAS). |
| CU-08 | Obtener un resumen ejecutivo con IA | Gerencia, Administración | Elige enfoque → el sistema envía solo indicadores agregados al modelo → muestra resumen, hallazgos y recomendaciones. | IA no configurada (503); tiempo agotado (504); respuesta inválida o error del proveedor (502); límite de uso (429). |

## 5. Historias de usuario con criterios de aceptación (por rol)

### Recepción
- **HU-01 (Must).** *Como recepcionista quiero agendar una sesión para un paciente para reservar el box y al profesional.*
  - Dado un horario libre de lunes a sábado dentro del horario del centro, cuando agendo, la atención queda *pendiente* y aparece en la lista.
  - Dado que el box ya tiene 2 reservas en esa hora, cuando intento agendar, veo «El box ya no tiene cupo en ese horario».
  - Dado que dejo la hora en 10:15, el formulario me indica de inmediato que debe ser un bloque de 30 minutos.
- **HU-02 (Must).** *Como recepcionista quiero confirmar, cancelar o reactivar atenciones para mantener la agenda al día.*
  - Solo veo como opciones las transiciones válidas del estado actual.
  - Al cancelar puedo escribir el motivo y queda en el historial de la atención.
- **HU-03 (Must).** *Como recepcionista quiero cobrar una sesión realizada con boleta para registrar el ingreso.*
  - La boleta queda pagada, con folio de demostración y la leyenda «sin validez tributaria».
  - Si el paciente es menor, el receptor es su apoderado.

### Área clínica
- **HU-04 (Must).** *Como terapeuta quiero ver solo mis pacientes y atenciones para proteger la información clínica.*
  - La lista de atenciones solo muestra las mías; si intento abrir otra, recibo «La atención no existe».
- **HU-05 (Must).** *Como terapeuta quiero marcar si la sesión se realizó o si el paciente no asistió.*
  - No puedo marcar como realizada una sesión futura ni cancelarla (lo hace recepción).

### Gerencia
- **HU-06 (Must).** *Como gerente quiero ver indicadores con su fórmula y comparación con el período anterior para evaluar el desempeño.*
  - Cada indicador muestra valor, variación (▲/▼ con texto «mejora/empeora») y su fórmula.
  - Cada gráfico tiene descripción y tabla de datos accesible.
- **HU-07 (Must).** *Como gerente quiero exportar el reporte gerencial a PDF y Excel con los filtros aplicados.*
  - El PDF trae título, fecha de emisión, filtros, numeración de páginas y un gráfico de ingresos.
  - La exportación queda registrada con mi usuario.
- **HU-08 (Should).** *Como gerente quiero un resumen en lenguaje natural del período para preparar la reunión mensual.*
  - El resumen se genera sin enviar datos de pacientes y advierte que debe revisarse.

### Administración
- **HU-09 (Must).** *Como administradora quiero saber quién cambió cada registro para auditar errores.*
  - La auditoría lista usuario, origen (panel/API/sistema), fecha y solo los campos que cambiaron.
- **HU-10 (Must).** *Como administradora quiero que ningún documento de la demo llegue al SII.*
  - Toda emisión usa folios de CAF de prueba, TrackID `DEMO-…` y el cliente del SII está bloqueado en el código.

## 6. Trazabilidad requerimiento → componente → asignatura

| Requerimiento | Componente principal | Asignatura |
|---|---|---|
| RF01–RF02, RNF01 | `src/Security`, `src/Kernel.php`, `lib/auth.php` | Programación Web II |
| RF03–RF04 | `AtencionService`, `Domain/EstadoReserva`, disparadores V4 | Diseño OO II · Base de Datos |
| RF05, RNF05 | `Validator`, `PacienteService`, CHECK V3 | Programación Web II · Base de Datos |
| RF07–RF08 | `Dte/MotorDteLegado` (Adapter) + `dte/lib/dte_demo.php` | Diseño OO II |
| RF09 | Migración V2 (disparadores de auditoría) | Base de Datos |
| RF10 | `DashboardService`, `assets/js/app/bi.js` | Inteligencia de Negocios I y II |
| RF11 | `ReporteService`, `Export/*` (Strategy) | Inteligencia de Negocios II · Diseño OO II |
| RF12 | `IaService`, `Ia/GroqClient` | Programación Web II |
| RNF04, RNF09 | `compose.yaml`, `docker/backup.sh`, `.github/workflows/ci.yml` | Programación Web II |

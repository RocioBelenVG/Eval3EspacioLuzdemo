# Informe de integración: articulación del módulo

El sistema es un único producto en un único dominio (la operación de un centro terapéutico) y cada asignatura del módulo
de desarrollo de software queda evidenciada en un componente concreto del código. La coherencia entre capas se garantiza
porque **la base de datos, la API, la interfaz y el dashboard comparten las mismas definiciones**: el flujo de estados
vive en un enum usado por la API y por el panel clásico y en un disparador; las cifras del dashboard, del reporte
gerencial y de la IA salen de las mismas vistas y del mismo servicio.

## 1. Por asignatura

| Asignatura | Componente que la evidencia | Por qué se diseñó así |
|---|---|---|
| **Lenguaje de Programación Web II** (eje) | API REST `/api/v1` (`src/Kernel.php`, controladores), JWT (`Security/Jwt`), roles por ruta, validación y errores estándar, OpenAPI + Swagger (`docs/openapi.yaml`, `/api/docs`), front end conectado a la API (`assets/js/app/*`), accesibilidad y responsive, IA (`IaService` + `GroqClient`), Docker multi-etapa, `compose.yaml`, CI/CD (`.github/workflows/ci.yml`). | El sistema heredado era SSR con sesión; se agregó una API sin estado para que las pantallas nuevas, Swagger y futuras integraciones usen el mismo contrato, y se contenerizó para ejecutarlo con un solo comando. |
| **Diseño de Software Orientado a Objetos II** | Arquitectura en capas, **Repository** (`src/Repository`), **inyección de dependencias** (`Container`, `App`), **Adapter** (`Dte/MotorDteLegado` sobre el motor DTE procedural), **Strategy** (`Export/*`), **State** (`Domain/EstadoReserva`); SOLID e interfaces pequeñas (`EmisorDte`, `IaClient`, `Exportador`); pruebas unitarias del dominio. | Permite evolucionar un sistema real sin reescribirlo: el adaptador encapsula el motor heredado en modo demo y las interfaces permiten sustituir la IA y el motor DTE por dobles en las pruebas. |
| **Base de Datos** | Modelo de 45 tablas en 3FN con desnormalizaciones justificadas, ER y diccionario (`02_modelo_datos.md`), 6 migraciones Phinx versionadas y reversibles, 34 CHECK + 37 FK + UNIQUE, 52 disparadores (cupo, calendario, flujo de estados, auditoría), 4 vistas para reportes, índices justificados, respaldo/restauración probados. | La base es el tercer nivel de validación y el lugar donde ninguna capa puede saltarse las reglas (incluido el código heredado y los scripts); la auditoría por disparadores cubre todos los caminos. |
| **Inteligencia de Negocios I** | 10 indicadores con fórmula, pregunta de negocio, comparación con el período anterior e interpretación (`DashboardService::indicadores`, `07_indicadores.md`). | Las preguntas salen de la operación real del centro: asistencia, ocupación de boxes, ingresos, retención y cobranza; su interpretación sostiene 4 recomendaciones concretas. |
| **Inteligencia de Negocios II** | Dashboard con 6 visualizaciones adecuadas al tipo de dato, filtros interactivos, actualización con los datos del sistema, narrativa y gráficos accesibles (`/admin/bi`); 3 reportes exportables a Excel/PDF/CSV e imprimibles (`ReporteService`). | Conectado al backend por endpoints de agregación (no hay datos copiados a mano); los mismos filtros alimentan pantalla, exportación e impresión. |

## 2. Trazabilidad requerimiento → componente → prueba → asignatura

| Requerimiento | Componente | Prueba / evidencia | Asignatura |
|---|---|---|---|
| RF01 Autenticación JWT | `AuthService`, `Jwt`, `AuthController` | `AuthTest` (9 casos), `ValidacionYSeguridadTest` | Web II |
| RF02 Roles y BOLA | `Kernel::rutas`, `AtencionService::listar/ver`, `PacienteRepository::listar` | `AtencionesTest::gerencia_lee…`, `PacientesTest::…area_clinica…` | Web II, OO II |
| RF03 Agenda con reglas | `AtencionService::crear/validarAgenda` | `AtencionesTest` (calendario, cupo) | OO II |
| RF04 Flujo de estados | `EstadoReserva`, disparador V4, `api/panel/reservas.php` | `EstadoReservaTest`, `AtencionesTest::…cerrada…` | OO II, BD |
| RF05 Pacientes (3 niveles de validación) | Formularios, `PacienteService`, CHECK V3 | `PacientesTest` | Web II, BD |
| RF07 Cobro demo | `MotorDteLegado` → `dte_demo.php` | `CobrosTest` | OO II |
| RF09 Auditoría | Disparadores V2, `AuditoriaRepository` | `AnalisisTest::…auditoria…` | BD |
| RF10 Dashboard | `DashboardService`, `bi.js` | `AnalisisTest::los_indicadores…`, `…series…`; axe 0 violaciones | BI I, BI II |
| RF11 Reportes | `ReporteService`, `Export/*` | `AnalisisTest::los_tres_formatos…` | BI II, OO II |
| RF12 IA | `IaService`, `GroqClient` | `AnalisisTest::la_ia…`, `…errores_de_la_ia…` | Web II |
| RNF04 Respaldo | `tools/respaldo.sh`, servicio `backup` | `evidencias/respaldo/…`, paso de CI | BD, Web II |
| RNF09 Contenedores | `Dockerfile`, `compose.yaml`, `ci.yml` | Pipeline (al publicar el repositorio) | Web II |

## 3. Reflexión crítica sobre las decisiones

* **Evolucionar vs. reescribir.** Partir del sistema real dio un producto completo y verosímil, pero obligó a convivir con
  código procedural heredado (sesión, mysqli, Tailwind por CDN). Se mitigó unificando reglas en la base y en un enum
  compartido; una alternativa más limpia (reescribir todo el panel como SPA) excedía el plazo y aportaba poco valor.
* **Auditoría en la base de datos.** Los disparadores garantizan cobertura total, a costa de lógica fuera del código PHP;
  se compensó generándolos desde la migración y documentando las variables de sesión que fijan el autor.
* **Modo demo de los documentos tributarios.** Se implementó en el código (no por configuración) para que sea imposible
  emitir de verdad desde la demo; el costo es que la demo no prueba la conexión real con el SII.
* **IA con datos agregados.** Se resignó la funcionalidad más vistosa (resumir fichas clínicas) por cumplimiento legal.
* **Limitaciones:** pantallas heredadas con accesibilidad mejorada solo en lo transversal; prueba con NVDA pendiente
  (la debe ejecutar la autora antes de la defensa); Lighthouse no se ejecutó (se usó axe-core, que la pauta acepta); la clave
  de IA vive solo en el .env del servidor y debe rotarse después de la defensa.
* **Mejoras futuras:** recordatorios automáticos de sesiones, portal del paciente, migrar el panel heredado a los
  componentes accesibles nuevos, caché de agregados para el dashboard y monitoreo con alertas externas.

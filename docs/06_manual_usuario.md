# 06 · Manual de usuario por rol

**Dirección:** https://trivoxa.cl/espacioluzdemo (con Docker: http://localhost:8080). Ingreso: **/admin/login**.
Cuentas de demostración: `admin@`, `recepcion@`, `clinica@` y `gerencia@espacioluzdemo.cl`; la contraseña común es la
variable `DEMO_PASSWORD` del archivo `.env` del servidor (no se publica en el repositorio).

> Esta es una **demo**: todos los pacientes, profesionales y clientes son ficticios, y las boletas y facturas son de
> demostración (folios de prueba, nunca enviadas al SII). Los correos no se envían: quedan guardados en el servidor.

## Elementos comunes

* **Menú lateral** por secciones; en celular se abre con el botón ☰ y se cierra con *Esc* o tocando fuera.
* **Saltar al contenido:** primer elemento al presionar *Tab*.
* Listados: busca, filtra, ordena (clic en el encabezado con flechas) y pagina con «Anterior/Siguiente».
* Errores: el campo con problema queda marcado y un resumen arriba del formulario enlaza a cada error.

## Recepción (`recepcion@`)

1. **Agendar** — *Análisis y operación → Atenciones → Nueva atención*. Elige profesional y box; busca y elige el paciente
   (déjalo vacío si es un arriendo de box); fecha (lunes a sábado), hora en bloques de 30 min y precio. *Agendar*.
2. **Confirmar, cancelar o reactivar** — en la fila, *Gestionar* → elige el nuevo estado (solo aparecen los permitidos) y
   un motivo opcional → *Guardar estado*. «Realizada» y «No asistió» se habilitan cuando llega la fecha.
3. **Cobrar una sesión** — en una atención *Realizada* sin cobro, *Gestionar* → medio de pago → *Emitir boleta demo*.
4. **Pagos de facturas** — *Facturación → Documentos* (panel clásico) para ver saldos y registrar pagos.
5. **Pacientes** — *Gestión del centro → Pacientes → Nuevo paciente*: RUT válido, fecha de nacimiento, consentimiento;
   si es menor, apoderado.
6. **Reporte operativo** — *Reportes → Reporte operativo de atenciones*: filtra, exporta (Excel, PDF, CSV) o imprime.

## Área clínica (`clinica@`)

1. **Mis pacientes** — *Pacientes*: solo los que atiendes. Abre la ficha por especialidad, registra evoluciones, adjunta
   informes y genera certificados o el PDF de la ficha.
2. **Mis atenciones** — *Atenciones*: solo las tuyas. En *Gestionar* marca **Realizada** o **No asistió** cuando la sesión
   ya ocurrió (cancelar o reprogramar lo hace Recepción).

## Gerencia (`gerencia@`)

1. **Dashboard** — al ingresar se abre *Dashboard e indicadores*. Ajusta el período (o usa 30 días / 3, 6 o 12 meses),
   especialidad, box o profesional y *Aplicar filtros*. Cada indicador muestra la variación contra el período anterior;
   «Fórmula y pregunta» explica cómo se calcula. Bajo cada gráfico, «Ver tabla de datos».
2. **Resumen con IA** — al final del dashboard elige el enfoque y *Generar resumen*. Solo se envían indicadores agregados;
   revisa el texto antes de usarlo.
3. **Reportes** — los tres reportes; los pacientes aparecen como «Paciente #id».

## Administración (`admin@`)

Todo lo anterior, más:
* **Auditoría** — quién creó, modificó o eliminó cada registro (panel, API o sistema) y las exportaciones realizadas.
* **Usuarios y Roles** — crear cuentas y asignar rol (Administrador, Editor/Recepción, Pacientes/Área clínica, Gerencia).
* **Empresas y SII** — en la demo muestra «Modo demostración»: no se cargan certificados ni CAF reales; «Solicitar folios»
  crea un CAF de prueba.
* **Boxes, Profesionales, Productos, Días y horarios, Apariencia** y edición del sitio público.
* **API (Swagger)** — documentación interactiva en `/api/docs`.

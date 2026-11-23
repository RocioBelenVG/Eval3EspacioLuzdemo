# Propuesta del dominio (Hito 1)

**Asignatura:** Lenguaje de Programación Web II, Evaluación 3
**Docente:** Enso Stefano Guidotti Robles
**Integrante:** Rocío Riquelme Torres (proyecto individual)
**Fecha de presentación:** semana 11

## 1. Dominio elegido

Agenda, fichas clínicas y cobros de un centro terapéutico de Los Ángeles (Región del Biobío) que atiende a niños,
adolescentes y adultos en terapia ocupacional y fonoaudiología, y que además arrienda por hora sus cinco boxes a
profesionales externos de psicología, psiquiatría y psicopedagogía, a quienes factura cada mes.

Corresponde al ejemplo «agenda y fichas de un centro de atención» de la pauta. Se parte de un sistema real del centro
(EspacioLuz) y se construye una copia de demostración, Espacio Luz Demo, con datos sintéticos y documentos tributarios
que nunca se envían al SII.

## 2. Problema y objetivo

La agenda, los cobros y la información clínica estaban repartidos entre planillas y mensajería. No había reglas
uniformes para cupos, horarios y estados, ni trazabilidad de los cambios, ni indicadores de ocupación, asistencia o
cobranza. El objetivo es un sistema web completo con API REST segura, validación en tres niveles, auditoría, dashboard,
reportes exportables, resumen con IA, contenedores, CI/CD y plan de despliegue.

## 3. Usuarios y roles

| Rol | Necesidad principal |
|---|---|
| Administración | Configurar el centro, gestionar usuarios y revisar la auditoría. |
| Recepción | Agendar atenciones, registrar pacientes y cobrar sesiones. |
| Área clínica | Ver solo sus pacientes y atenciones, y registrar la ficha clínica. |
| Gerencia | Leer indicadores, reportes y el resumen con IA, sin modificar datos. |

## 4. Casos de uso principales

1. Agendar una atención (sesión con paciente o arriendo de box).
2. Gestionar el estado de una atención: pendiente, confirmada, realizada, no asistió o cancelada.
3. Registrar un paciente con RUT válido y consentimiento informado.
4. Registrar la evolución clínica de una sesión.
5. Cobrar una sesión con boleta de demostración.
6. Registrar el pago de una factura de arriendo.
7. Analizar el desempeño en el dashboard y exportar reportes.
8. Obtener un resumen ejecutivo con IA a partir de los indicadores.

El detalle, con criterios de aceptación por rol, está en [01 Requerimientos](01_requerimientos.md).

## 5. Alcance del producto mínimo viable

**Incluye:** API `/api/v1` con JWT y cuatro roles; módulos de Atenciones, Dashboard, Reportes y Auditoría conectados a la
API; panel heredado para pacientes, fichas, clientes y documentos; base de datos versionada con migraciones; datos
sintéticos de 12 meses (más de 500 atenciones); reportes en Excel, PDF y CSV; resumen con IA; Docker, Compose, pipeline
y plan de despliegue.

**Queda fuera:** emisión tributaria real, pagos en línea y envío real de correos.

## 6. Datos de prueba

Se generan con una semilla fija: 520 pacientes, 5.520 atenciones entre noviembre de 2025 y diciembre de 2026 y 2.446
documentos de demostración. Ningún dato corresponde a personas reales.

## 7. Aprobación

La aprobación del dominio por el docente se registra en [evidencias/hito1](evidencias/hito1/) (correo, acta o captura
de la retroalimentación del Hito 1).

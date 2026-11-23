<?php
/**
 * API pública de agenda online.
 * GET  ?prof=<slug>&fecha=YYYY-MM-DD  -> { ok, slots:[HH:MM,...], duracion }
 * POST { profesional_id, producto_id, fecha, hora, nombre, email, telefono }
 *      -> crea la reserva (estado 'pendiente'), la une a la ficha del paciente si ya
 *         existe (mismo correo o teléfono) y envía la confirmación por correo.
 *         NO emite boleta: la boleta se emite en el panel cuando el paciente paga
 *         (queda «por cobrar»). Devuelve { ok, codigo, boleta:null, email_enviado }.
 * El pago (Flow) se conectará como último paso más adelante.
 */
declare(strict_types=1);

require_once APP_ROOT . '/lib/disponibilidad.php';

/* ---------------------------------------------------------------- GET slots */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $slug = trim((string) ($_GET['prof'] ?? ''));
    $fecha = trim((string) ($_GET['fecha'] ?? ''));
    $prof = $slug !== '' ? profesionalAgendablePorSlug($slug) : null;
    if (!$prof) {
        json_response(['ok' => false, 'error' => 'Profesional no disponible.'], 404);
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        json_response(['ok' => false, 'error' => 'Fecha inválida.'], 400);
    }
    require_once APP_ROOT . '/lib/feriados.php';
    $cerrado = diaCerradoMotivo($fecha);
    json_response(['ok' => true, 'slots' => $cerrado !== null ? [] : agendaSlotsDisponibles($prof, $fecha), 'duracion' => AGENDA_DUR_MIN,
        'cerrado' => $cerrado !== null ? (esDomingo($fecha) ? 'Los domingos no atendemos. Elige otro día.' : 'Ese día es feriado y no atendemos. Elige otro día.') : null]);
}

/* --------------------------------------------------------------- POST crear */
@set_time_limit(120);
$body = read_json_body() ?? [];

// Honeypot anti-bot (campo oculto que un humano no llena)
if (trim((string) ($body['website'] ?? '')) !== '') {
    json_response(['ok' => true, 'codigo' => 'OK']); // simula éxito sin crear
}

$res = agendaCrearReserva([
    'profesional_id' => $body['profesional_id'] ?? 0,
    'producto_id'    => $body['producto_id'] ?? 0,
    'fecha'          => $body['fecha'] ?? '',
    'hora'           => $body['hora'] ?? '',
    'nombre'         => $body['nombre'] ?? '',
    'email'          => $body['email'] ?? '',
    'telefono'       => $body['telefono'] ?? '',
]);

if (!$res['ok']) {
    json_response(['ok' => false, 'error' => $res['error']], $res['status'] ?? 400);
}

$r = $res['reserva'];

// La reserva NO genera boleta: queda «por cobrar» y se boletea en el panel cuando se paga.
// Si el paciente ya tiene ficha (mismo correo o teléfono), la reserva queda unida a ella.
require_once APP_ROOT . '/lib/agenda_boleta.php';
$boleta = null;
$boletaId = null;
try { agendaVincularPaciente((int) ($r['reserva_id'] ?? 0), (string) ($r['email'] ?? ''), (string) ($r['telefono'] ?? '')); } catch (Throwable $e) { error_log('[agenda paciente] ' . $e->getMessage()); }

// Correo al paciente con la boleta en PDF (best-effort).
$emailEnviado = false;
try { $emailEnviado = agendaEnviarBoletaCorreo($r, $boletaId); } catch (Throwable $e) { error_log('[agenda correo] ' . $e->getMessage()); }

json_response([
    'ok' => true,
    'codigo' => $res['codigo'],
    'boleta' => $boleta['folio'] ?? null,
    'email_enviado' => $emailEnviado,
]);

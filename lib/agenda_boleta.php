<?php
/**
 * Agenda online · generación de la boleta (MVP) de una reserva y envío por
 * correo AL CORREO QUE INGRESÓ EL PACIENTE con el documento adjunto en PDF.
 *
 * La boleta se emite desde la EMPRESA de la especialista elegida, con los datos
 * que ingresó el paciente y el servicio (producto) seleccionado. Como prestación
 * de salud, va exenta de IVA. Mientras no haya certificado + CAF, la boleta es
 * interna (MVP); al conectar el DTE pasará a timbrada. El correo usa el emisor
 * compartido (lib/mailer.php): remitente del dominio del hosting y, si algún día
 * se configuran credenciales SMTP, envío autenticado.
 */
declare(strict_types=1);

require_once APP_ROOT . '/lib/boletas.php';
require_once APP_ROOT . '/lib/mailer.php';

/**
 * Crea la boleta MVP de una reserva (desde la empresa de la especialista).
 * @return array{ok:bool,id:int,folio:int,tipo:string}|null
 */
function agendaEmitirBoletaReserva(array $r): ?array
{
    $empresaId = (int) ($r['empresa_id'] ?? 0);
    if ($empresaId <= 0) {
        return null; // la profesional no tiene empresa emisora asociada
    }
    $precio = (int) ($r['precio'] ?? 0);
    $res = crearBoleta([
        'empresa_id'     => $empresaId,
        'receptor_nombre' => (string) ($r['nombre'] ?? ''),
        'receptor_email'  => (string) ($r['email'] ?? ''),
        'reserva_id'     => (int) ($r['reserva_id'] ?? 0),
        'medio_pago'     => 'otro',
        'glosa'          => 'Reserva de hora · ' . ($r['fecha'] ?? '') . ' ' . ($r['hora'] ?? '') . ' · ' . ($r['profesional'] ?? ''),
        'items'          => [[
            'descripcion' => (string) ($r['servicio'] ?? 'Sesión'),
            'cantidad'    => 1,
            'precio_unit' => $precio,
            'afecto'      => 0, // prestación de salud → exento de IVA
        ]],
    ]);
    return !empty($res['ok']) ? $res : null;
}

/**
 * Une una reserva de la agenda online con la ficha del paciente si ya existe
 * (mismo correo, o mismo teléfono por sus dígitos). No crea fichas nuevas.
 */
function agendaVincularPaciente(int $reservaId, string $email, string $telefono): ?int
{
    if ($reservaId <= 0) {
        return null;
    }
    $email = mb_strtolower(trim($email), 'UTF-8');
    $dig = preg_replace('/\D/', '', $telefono);
    $dig = strlen($dig) >= 8 ? substr($dig, -8) : '';
    $pid = null;
    if ($email !== '') {
        $st = db()->prepare("SELECT id FROM pacientes WHERE activo = 1 AND LOWER(email) = ? ORDER BY id LIMIT 1");
        $st->execute([$email]);
        $pid = $st->fetchColumn() ?: null;
    }
    if (!$pid && $dig !== '') {
        foreach (db()->query("SELECT id, telefono FROM pacientes WHERE activo = 1 AND telefono IS NOT NULL") as $p) {
            if (substr(preg_replace('/\D/', '', (string) $p['telefono']), -8) === $dig) {
                $pid = $p['id'];
                break;
            }
        }
    }
    if ($pid) {
        db()->prepare("UPDATE reservas SET paciente_id = ? WHERE id = ? AND paciente_id IS NULL")->execute([(int) $pid, $reservaId]);
    }
    return $pid ? (int) $pid : null;
}

/** Remitente del correo (compat). Delega en el emisor compartido. */
function agendaMailFrom(): string
{
    return eluz_mail_from();
}

/**
 * Envía al CORREO DEL PACIENTE el comprobante de la reserva con la boleta en PDF
 * adjunta. Devuelve true si el envío fue aceptado.
 */
function agendaEnviarBoletaCorreo(array $r, ?int $boletaId): bool
{
    $dest = trim((string) ($r['email'] ?? ''));
    if (!filter_var($dest, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    // PDF de la boleta (si se generó)
    $pdf = null;
    if ($boletaId) {
        require_once APP_ROOT . '/lib/boleta_pdf.php';
        $b = getBoleta($boletaId);
        if ($b) {
            try { $pdf = boleta_pdf_bytes($b, 'carta'); } catch (Throwable $e) { $pdf = null; }
        }
    }

    $fechaFmt = date('d-m-Y', strtotime((string) ($r['fecha'] ?? 'now')));
    $precio = (int) ($r['precio'] ?? 0);
    $C = ['cafe' => '#5C4033', 'terr' => '#D97757', 'crema' => '#F5F1E8', 'linea' => '#E4DAC9', 'gris' => '#8a7c70'];

    $fila = fn(string $k, string $v, bool $b = false) =>
        '<tr><td style="padding:10px 14px;color:' . $C['gris'] . ';font-size:12px;text-transform:uppercase;">' . e($k) . '</td>'
        . '<td style="padding:10px 14px;font-size:14px;' . ($b ? 'font-weight:bold;' : '') . '">' . e($v) . '</td></tr>';

    $html = '<!DOCTYPE html><html><body style="margin:0;padding:24px;background:#F3EEE4;font-family:Arial,Helvetica,sans-serif;color:' . $C['cafe'] . ';">'
        . '<div style="max-width:560px;margin:0 auto;background:#fff;border:1px solid ' . $C['linea'] . ';border-radius:14px;overflow:hidden;">'
        . '<div style="height:6px;background:' . $C['terr'] . ';"></div>'
        . '<div style="padding:26px 30px;">'
        . '<h1 style="margin:0;font-size:22px;color:' . $C['cafe'] . ';">Espacio Luz</h1>'
        . '<p style="margin:4px 0 18px;font-size:13px;color:' . $C['gris'] . ';">Comprobante de reserva de hora</p>'
        . '<p style="font-size:15px;">Hola ' . e((string) ($r['nombre'] ?? '')) . ', recibimos tu reserva. Estos son los detalles:</p>'
        . '<table style="width:100%;border-collapse:collapse;margin:16px 0;background:' . $C['crema'] . ';border-radius:10px;">'
        . $fila('Profesional', (string) ($r['profesional'] ?? ''), true)
        . $fila('Atención', (string) ($r['especialidad'] ?: '—'))
        . $fila('Servicio', (string) ($r['servicio'] ?? '—'))
        . $fila('Valor', '$' . number_format($precio, 0, ',', '.'), true)
        . $fila('Fecha', $fechaFmt, true)
        . $fila('Hora', ((string) ($r['hora'] ?? '')) . ' hrs (' . (int) ($r['duracion'] ?? 45) . ' min)', true)
        . $fila('Código', strtoupper((string) ($r['reserva_publico'] ?? '')))
        . '</table>'
        . '<div style="padding:12px 14px;background:#FBF3EC;border:1px dashed ' . $C['terr'] . ';border-radius:8px;font-size:12px;line-height:1.5;">'
        . ($pdf ? 'Adjuntamos tu <strong>boleta</strong> en PDF. ' : '')
        . 'Tu reserva quedó <strong>registrada</strong>. Nos pondremos en contacto para confirmar. Si necesitas cambiarla o cancelarla, respóndenos indicando tu código.'
        . '</div>'
        . '<p style="margin-top:20px;font-size:12px;color:' . $C['gris'] . ';">Gracias por confiar en Espacio Luz.</p>'
        . '</div></div></body></html>';

    try {
        $mail = eluz_mailer();
        $mail->addAddress($dest, (string) ($r['nombre'] ?? ''));
        $mail->Subject = 'Tu reserva en Espacio Luz — ' . $fechaFmt . ' ' . ($r['hora'] ?? '');
        $mail->isHTML(true);
        $mail->Body = $html;
        $mail->AltBody = 'Tu reserva en Espacio Luz quedó registrada para el ' . $fechaFmt . ' a las ' . ($r['hora'] ?? '') . '.' . ($pdf ? ' Adjuntamos tu boleta en PDF.' : '');
        if ($pdf) {
            $mail->addStringAttachment($pdf['bytes'], $pdf['filename'], 'base64', 'application/pdf');
        }
        return $mail->send();
    } catch (Throwable $e) {
        error_log('[agenda mail] ' . $e->getMessage());
        return false;
    }
}

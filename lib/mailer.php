<?php
/**
 * Emisor de correo compartido (PHPMailer) para EspacioLuz.
 * Lo usan la agenda (boleta al correo del paciente) y el panel (boleta al
 * correo que indique el administrador). Si hay credenciales SMTP en variables
 * de entorno, se usa SMTP autenticado (entrega garantizada); si no, el correo
 * del servidor (mail) con remitente del dominio del hosting.
 */
declare(strict_types=1);

/** Remitente del correo (configurable). Por defecto, dominio del hosting. */
function eluz_mail_from(): string
{
    return getenv('ELUZ_MAIL_FROM') ?: (getenv('ELUZ_EMISOR_EMAIL') ?: 'reservas@trivoxa.cl');
}

/**
 * Devuelve un PHPMailer configurado (remitente + transporte). Lanza si la
 * librería no está disponible.
 */
function eluz_mailer(): \PHPMailer\PHPMailer\PHPMailer
{
    require_once APP_ROOT . '/dte/vendor/autoload.php';
    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        $pm = APP_ROOT . '/dte/vendor/phpmailer/phpmailer/src/';
        foreach (['Exception.php', 'PHPMailer.php', 'SMTP.php'] as $f) {
            if (is_file($pm . $f)) require_once $pm . $f;
        }
    }
    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        throw new RuntimeException('PHPMailer no está disponible.');
    }

    $from = eluz_mail_from();
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    if (getenv('ELUZ_SMTP_HOST')) {
        $mail->isSMTP();
        $mail->Host       = getenv('ELUZ_SMTP_HOST');
        $mail->SMTPAuth   = true;
        $mail->Username   = getenv('ELUZ_SMTP_USER') ?: $from;
        $mail->Password   = getenv('ELUZ_SMTP_PASS') ?: '';
        $mail->Port       = (int) (getenv('ELUZ_SMTP_PORT') ?: 587);
        $mail->SMTPSecure = ((int) $mail->Port === 465) ? 'ssl' : 'tls';
    } else {
        $mail->isMail();
    }
    $mail->CharSet = 'UTF-8';
    $mail->setFrom($from, 'Espacio Luz');
    $mail->Sender = $from;               // Return-Path (ayuda a la entregabilidad)
    $mail->addReplyTo($from, 'Espacio Luz');
    return $mail;
}

/**
 * Envía la boleta/factura (PDF) de un documento interno al correo indicado.
 * Úsalo desde el panel: el administrador elige el destinatario.
 * @return array{ok:bool, error?:string}
 */
function enviarBoletaPorCorreo(int $boletaId, string $dest, ?string $destNombre = null): array
{
    if (!filter_var($dest, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Correo de destino inválido.'];
    }
    require_once APP_ROOT . '/lib/boletas.php';
    require_once APP_ROOT . '/lib/boleta_pdf.php';
    $b = getBoleta($boletaId);
    if (!$b) {
        return ['ok' => false, 'error' => 'Documento no encontrado.'];
    }
    $docLabel = tipoDocLabel($b['tipo'] ?? 'boleta');
    $folio = str_pad((string) $b['folio'], 6, '0', STR_PAD_LEFT);
    $em = emisorDeBoleta($b);
    $emisorNombre = $em['razon_social'] ?? $em['nombre'];

    $pdf = null;
    try { $pdf = boleta_pdf_bytes($b, 'carta'); } catch (Throwable $e) { $pdf = null; }

    $C = ['cafe' => '#5C4033', 'terr' => '#D97757', 'crema' => '#F5F1E8', 'linea' => '#E4DAC9', 'gris' => '#8a7c70'];
    $html = '<!DOCTYPE html><html><body style="margin:0;padding:24px;background:#F3EEE4;font-family:Arial,Helvetica,sans-serif;color:' . $C['cafe'] . ';">'
        . '<div style="max-width:560px;margin:0 auto;background:#fff;border:1px solid ' . $C['linea'] . ';border-radius:14px;overflow:hidden;">'
        . '<div style="height:6px;background:' . $C['terr'] . ';"></div>'
        . '<div style="padding:26px 30px;">'
        . '<h1 style="margin:0;font-size:22px;">' . e($emisorNombre) . '</h1>'
        . '<p style="margin:4px 0 16px;font-size:13px;color:' . $C['gris'] . ';">' . e($docLabel) . ' N° ' . e($folio) . '</p>'
        . '<p style="font-size:15px;">Estimado/a ' . e($b['receptor_nombre']) . ', adjuntamos su ' . mb_strtolower($docLabel) . ' en PDF.</p>'
        . '<p style="margin-top:14px;font-size:14px;color:' . $C['gris'] . ';">Total: <strong style="color:' . $C['cafe'] . ';">$' . number_format((int) $b['total_clp'], 0, ',', '.') . '</strong></p>'
        . '<p style="margin-top:18px;font-size:12px;color:' . $C['gris'] . ';">Gracias por confiar en ' . e($emisorNombre) . '.</p>'
        . '</div></div></body></html>';

    try {
        $mail = eluz_mailer();
        $mail->addAddress($dest, $destNombre ?: ($b['receptor_nombre'] ?? ''));
        $mail->Subject = $docLabel . ' N° ' . $folio . ' — ' . $emisorNombre;
        $mail->isHTML(true);
        $mail->Body = $html;
        $mail->AltBody = 'Adjuntamos su ' . mb_strtolower($docLabel) . ' N° ' . $folio . ' en PDF.';
        if ($pdf) {
            $mail->addStringAttachment($pdf['bytes'], $pdf['filename'], 'base64', 'application/pdf');
        }
        $ok = $mail->send();
        return $ok ? ['ok' => true] : ['ok' => false, 'error' => 'El servidor no pudo enviar el correo.'];
    } catch (Throwable $e) {
        error_log('[boleta mail] ' . $e->getMessage());
        return ['ok' => false, 'error' => 'No se pudo enviar el correo: ' . $e->getMessage()];
    }
}

<?php

declare(strict_types=1);

namespace EspacioLuz\Infrastructure;

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Transporte de correo de la demo: en vez de entregar el mensaje, lo guarda como
 * archivo .eml en storage/logs/correos. Así los flujos que envían boletas por
 * correo funcionan completos sin escribirle a nadie (los datos son ficticios).
 */
final class ArchivoMailer extends PHPMailer
{
    public function postSend(): bool
    {
        $dir = (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2)) . '/storage/logs/correos';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $archivo = $dir . '/' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.eml';
        return file_put_contents($archivo, $this->getSentMIMEMessage()) !== false;
    }
}

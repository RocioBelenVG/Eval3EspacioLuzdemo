<?php
declare(strict_types=1);

namespace EspacioLuz\Dte;

/**
 * Adaptador (patrón Adapter) entre la API y el motor DTE heredado de EspacioLuz
 * (dte/bootstrap.php, funciones procedurales con mysqli). Traduce la llamada orientada a
 * objetos a dte_emitir(), que en esta copia corre en modo demostración: folios de un
 * CAF de prueba, TrackID DEMO-… y ningún envío al SII (dte/lib/dte_demo.php).
 */
final class MotorDteLegado implements EmisorDte
{
    public function __construct(private readonly string $raizApp)
    {
    }

    public function emitir(int $empresaId, int $tipo, array $items, array $receptor, ?int $pacienteId, string $observaciones, int $usuarioId, string $usuarioNombre): array
    {
        if (!defined('APP_ROOT')) {
            define('APP_ROOT', $this->raizApp);
        }
        [$conex, $cfg] = require $this->raizApp . '/dte/bootstrap.php';
        if (!function_exists('dte_modo_demo') || !dte_modo_demo()) {
            // Defensa en profundidad: la API de la demo jamás emite fuera del modo demostración.
            return ['ok' => false, 'error' => 'El motor DTE no está en modo demostración.'];
        }
        // Auditoría: la conexión mysqli del motor también queda a nombre del usuario de la API.
        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 45);
        if ($st = $conex->prepare("SET @app_usuario_id = ?, @app_usuario_nombre = ?, @app_origen = 'api', @app_ip = ?")) {
            $st->bind_param('iss', $usuarioId, $usuarioNombre, $ip);
            $st->execute();
            $st->close();
        }
        return dte_emitir($conex, $cfg, [
            'empresa_id' => $empresaId,
            'tipo' => $tipo,
            'items' => $items,
            'receptor' => $receptor,
            'paciente_id' => $pacienteId,
            'fecha_emision' => date('Y-m-d'),
            'forma_pago' => 1,
            'observaciones' => $observaciones,
        ]);
    }
}

<?php
declare(strict_types=1);

namespace EspacioLuz\Controller;

use EspacioLuz\Http\Request;
use EspacioLuz\Http\Response;
use EspacioLuz\Infrastructure\Database;

/** Estado del servicio para los health checks de Docker y del pipeline (público, sin datos sensibles). */
final class HealthController
{
    public function __construct(private readonly Database $db)
    {
    }

    public function health(Request $r): Response
    {
        try {
            $this->db->pdo->query('SELECT 1')->fetchColumn();
            $migraciones = (int) $this->db->pdo->query('SELECT COUNT(*) FROM phinxlog')->fetchColumn();
            return Response::json(['status' => 'ok', 'db' => 'ok', 'migraciones' => $migraciones, 'version' => 'v1', 'modo_dte' => 'demo']);
        } catch (\Throwable) {
            return Response::json(['status' => 'error', 'db' => 'sin conexión'], 503);
        }
    }
}

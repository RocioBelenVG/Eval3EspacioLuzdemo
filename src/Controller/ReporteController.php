<?php
declare(strict_types=1);

namespace EspacioLuz\Controller;

use EspacioLuz\Http\Request;
use EspacioLuz\Http\Response;
use EspacioLuz\Service\ReporteService;

final class ReporteController
{
    public function __construct(private readonly ReporteService $svc)
    {
    }

    public function index(Request $r): Response
    {
        $u = $r->user();
        $lista = [];
        foreach (ReporteService::REPORTES as $clave => $def) {
            if (in_array($u->rol->value, $def['roles'], true)) {
                $lista[] = ['clave' => $clave, 'titulo' => $def['titulo'], 'formatos' => ['csv', 'xlsx', 'pdf']];
            }
        }
        return Response::json(['data' => $lista]);
    }

    public function datos(Request $r): Response
    {
        return Response::json($this->svc->datos($r->user(), $r->param('reporte'), $r->query));
    }

    public function exportar(Request $r): Response
    {
        [$reporte, $exportador] = $this->svc->exportar($r->user(), $r->param('reporte'), $r->query, $r->ip);
        $nombre = $reporte->nombreArchivo($exportador->extension());
        return new Response(200, $exportador->generar($reporte), [
            'Content-Type' => $exportador->tipoContenido(),
            'Content-Disposition' => 'attachment; filename="' . $nombre . '"',
            'X-Filas' => (string) count($reporte->filas),
            'Access-Control-Expose-Headers' => 'Content-Disposition, X-Filas',
        ]);
    }
}

<?php
declare(strict_types=1);

namespace EspacioLuz\Controller;

use EspacioLuz\Domain\EstadoReserva;
use EspacioLuz\Http\Request;
use EspacioLuz\Http\Response;
use EspacioLuz\Repository\CatalogoRepository;
use EspacioLuz\Service\AtencionService;

/** Catálogos de solo lectura que alimentan los filtros y formularios de la interfaz. */
final class CatalogoController
{
    public function __construct(private readonly CatalogoRepository $repo)
    {
    }

    public function profesionales(Request $r): Response
    {
        return Response::json(['data' => $this->repo->profesionales()]);
    }

    public function boxes(Request $r): Response
    {
        $boxes = array_map(function ($b) {
            $t = json_decode((string) $b['tarifas'], true) ?: [];
            unset($b['tarifas']);
            return $b + ['precio_hora' => (int) ($t[0]['precio'] ?? 0)];
        }, $this->repo->boxes());
        return Response::json(['data' => $boxes]);
    }

    public function opciones(Request $r): Response
    {
        return Response::json(['data' => [
            'especialidades' => $this->repo->especialidades(),
            'comunas' => $this->repo->comunas(),
            'previsiones' => $this->repo->previsiones(),
            'tipos_atencion' => AtencionService::TIPOS_ATENCION,
            'medios_pago' => AtencionService::MEDIOS_PAGO,
            'estados' => array_map(fn (EstadoReserva $e) => ['valor' => $e->value, 'etiqueta' => $e->etiqueta(),
                'siguientes' => array_map(fn (EstadoReserva $s) => $s->value, $e->siguientes())], EstadoReserva::cases()),
        ]]);
    }
}

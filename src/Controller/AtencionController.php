<?php
declare(strict_types=1);

namespace EspacioLuz\Controller;

use EspacioLuz\Http\Request;
use EspacioLuz\Http\Response;
use EspacioLuz\Service\AtencionService;

final class AtencionController
{
    public function __construct(private readonly AtencionService $svc)
    {
    }

    public function index(Request $r): Response
    {
        return Response::json($this->svc->listar($r->user(), $r->query));
    }

    public function show(Request $r): Response
    {
        return Response::json(['data' => $this->svc->ver($r->user(), (int) $r->param('id'))]);
    }

    public function store(Request $r): Response
    {
        $a = $this->svc->crear($r->user(), $r->json());
        return Response::json(['data' => $a], 201, ['Location' => '/api/v1/atenciones/' . $a['id']]);
    }

    public function estado(Request $r): Response
    {
        return Response::json(['data' => $this->svc->cambiarEstado($r->user(), (int) $r->param('id'), $r->json())]);
    }

    public function reprogramar(Request $r): Response
    {
        return Response::json(['data' => $this->svc->reprogramar($r->user(), (int) $r->param('id'), $r->json())]);
    }

    public function boleta(Request $r): Response
    {
        $doc = $this->svc->emitirBoleta($r->user(), (int) $r->param('id'), $r->json());
        return Response::json(['data' => $doc, 'aviso' => 'Boleta de DEMOSTRACIÓN: no fue enviada al SII y no tiene validez tributaria.'], 201,
            ['Location' => '/api/v1/documentos/' . ($doc['id'] ?? '')]);
    }
}

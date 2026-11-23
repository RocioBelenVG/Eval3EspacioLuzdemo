<?php
declare(strict_types=1);

namespace EspacioLuz\Controller;

use EspacioLuz\Http\Request;
use EspacioLuz\Http\Response;
use EspacioLuz\Service\PacienteService;

final class PacienteController
{
    public function __construct(private readonly PacienteService $svc)
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
        $p = $this->svc->crear($r->user(), $r->json());
        return Response::json(['data' => $p], 201, ['Location' => '/api/v1/pacientes/' . $p['id']]);
    }

    public function update(Request $r): Response
    {
        return Response::json(['data' => $this->svc->actualizar($r->user(), (int) $r->param('id'), $r->json())]);
    }
}

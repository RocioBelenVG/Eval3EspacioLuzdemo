<?php
declare(strict_types=1);

namespace EspacioLuz\Controller;

use EspacioLuz\Http\Request;
use EspacioLuz\Http\Response;
use EspacioLuz\Service\DocumentoService;

final class DocumentoController
{
    public function __construct(private readonly DocumentoService $svc)
    {
    }

    public function index(Request $r): Response
    {
        return Response::json($this->svc->listar($r->query));
    }

    public function show(Request $r): Response
    {
        return Response::json(['data' => $this->svc->ver((int) $r->param('id'))]);
    }

    public function pago(Request $r): Response
    {
        return Response::json(['data' => $this->svc->registrarPago($r->user(), (int) $r->param('id'), $r->json())], 201);
    }
}

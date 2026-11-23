<?php
declare(strict_types=1);

namespace EspacioLuz\Controller;

use EspacioLuz\Http\Request;
use EspacioLuz\Http\Response;
use EspacioLuz\Repository\AuditoriaRepository;
use EspacioLuz\Validation\Validator;

final class AuditoriaController
{
    public function __construct(private readonly AuditoriaRepository $repo)
    {
    }

    public function index(Request $r): Response
    {
        $f = Validator::check($r->query, [
            'tabla' => ['string', 'max:40', 'regex:/^[a-z_]+$/'],
            'accion' => ['in:crear,modificar,eliminar'],
            'origen' => ['in:panel,api,sistema'],
            'usuario_id' => ['int', 'min:1'],
            'registro_id' => ['string', 'max:40'],
            'desde' => ['date'],
            'hasta' => ['date'],
        ]);
        [$page, $perPage] = Validator::paginacion($r->query);
        return Response::json($this->repo->listar($f, $page, $perPage));
    }

    public function exportaciones(Request $r): Response
    {
        return Response::json(['data' => $this->repo->exportaciones()]);
    }
}

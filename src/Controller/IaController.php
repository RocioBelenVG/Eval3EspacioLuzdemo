<?php
declare(strict_types=1);

namespace EspacioLuz\Controller;

use EspacioLuz\Http\Request;
use EspacioLuz\Http\Response;
use EspacioLuz\Service\IaService;

final class IaController
{
    public function __construct(private readonly IaService $svc)
    {
    }

    public function resumen(Request $r): Response
    {
        return Response::json(['data' => $this->svc->resumen($r->user(), $r->json())]);
    }
}

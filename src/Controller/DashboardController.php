<?php
declare(strict_types=1);

namespace EspacioLuz\Controller;

use EspacioLuz\Http\Request;
use EspacioLuz\Http\Response;
use EspacioLuz\Service\DashboardService;

final class DashboardController
{
    public function __construct(private readonly DashboardService $svc)
    {
    }

    public function indicadores(Request $r): Response
    {
        $f = $this->svc->filtros($r->query);
        return Response::json(['filtros' => $f, 'periodo_anterior' => DashboardService::anterior($f), 'data' => $this->svc->indicadores($f)]);
    }

    public function series(Request $r): Response
    {
        $f = $this->svc->filtros($r->query);
        return Response::json(['filtros' => $f, 'data' => $this->svc->series($f)]);
    }
}

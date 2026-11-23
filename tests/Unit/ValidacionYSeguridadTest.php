<?php
declare(strict_types=1);

namespace EspacioLuz\Tests\Unit;

use EspacioLuz\Exception\ApiException;
use EspacioLuz\Export\CsvExportador;
use EspacioLuz\Export\XlsxExportador;
use EspacioLuz\Http\Router;
use EspacioLuz\Kernel;
use EspacioLuz\Report\Reporte;
use EspacioLuz\Security\Jwt;
use EspacioLuz\Service\DashboardService;
use EspacioLuz\Service\PacienteService;
use EspacioLuz\Validation\Validator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Validador, JWT, enrutador, exportadores y utilidades de los servicios. */
final class ValidacionYSeguridadTest extends TestCase
{
    #[Test]
    public function el_validador_convierte_tipos_y_junta_todos_los_errores(): void
    {
        $ok = Validator::check(['n' => '15', 'f' => '2026-02-28', 'r' => '12345678-5', 'b' => 'true', 'x' => 'ignorado'],
            ['n' => ['int', 'min:1'], 'f' => ['date'], 'r' => ['rut'], 'b' => ['bool']]);
        $this->assertSame(['n' => 15, 'f' => '2026-02-28', 'r' => '12345678-5', 'b' => true], $ok);
        try {
            Validator::check(['f' => '2026-02-30', 'r' => '12345678-0', 'e' => 'x'], ['f' => ['date'], 'r' => ['rut'], 'e' => ['email'], 'req' => ['required']]);
            $this->fail('Debió lanzar 422');
        } catch (ApiException $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame(['f', 'r', 'e', 'req'], array_column($e->details, 'field'));
        }
    }

    #[Test]
    public function la_paginacion_limita_el_tamano_de_pagina(): void
    {
        $this->assertSame([1, 25], Validator::paginacion([]));
        $this->expectException(ApiException::class);
        Validator::paginacion(['per_page' => 1000]);
    }

    #[Test]
    public function el_jwt_rechaza_firmas_ajenas_y_tokens_vencidos(): void
    {
        $a = new Jwt(str_repeat('a', 40), 60);
        $b = new Jwt(str_repeat('b', 40), 60);
        $t = $a->encode(['sub' => 7]);
        $this->assertSame(7, $a->decode($t)['sub']);
        try {
            $b->decode($t);
            $this->fail('Firma ajena aceptada');
        } catch (ApiException $e) {
            $this->assertSame('TOKEN_INVALIDO', $e->errorCode);
        }
        $this->expectExceptionMessage('expiró');
        $a->decode($a->encode(['sub' => 7], time() - 600));
    }

    #[Test]
    public function un_secreto_corto_no_se_acepta(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Jwt('corto');
    }

    #[Test]
    public function el_router_responde_405_con_los_metodos_permitidos(): void
    {
        $r = Kernel::rutas();
        $this->assertSame('42', $r->match('GET', '/atenciones/42')['params']['id']);
        try {
            $r->match('DELETE', '/atenciones/42');
            $this->fail('Debió responder 405');
        } catch (ApiException $e) {
            $this->assertSame(405, $e->status);
            $this->assertStringContainsString('PATCH', $e->headers['Allow']);
        }
        $this->expectException(ApiException::class);
        (new Router())->match('GET', '/atenciones/0');
    }

    #[Test]
    public function el_periodo_anterior_tiene_la_misma_duracion(): void
    {
        $ant = DashboardService::anterior(['desde' => '2026-03-01', 'hasta' => '2026-03-31']);
        $this->assertSame(['desde' => '2026-01-29', 'hasta' => '2026-02-28'], ['desde' => $ant['desde'], 'hasta' => $ant['hasta']]);
        $this->assertSame('12.345.678-5', PacienteService::formatoRut('12345678-5'));
    }

    #[Test]
    public function los_exportadores_generan_csv_y_xlsx_validos(): void
    {
        $r = new Reporte('prueba', 'Reporte de prueba', ['fecha' => ['Fecha', 'fecha'], 'monto' => ['Monto', 'clp'], 'pct' => ['Pct', 'pct']],
            [['fecha' => '2026-05-04', 'monto' => 35000, 'pct' => 12.5]], ['monto' => 35000], ['Desde' => '01-05-2026'], 'Empresa', 'Emisor');
        $csv = (new CsvExportador())->generar($r);
        $this->assertSame("\xEF\xBB\xBFFecha;Monto;Pct\n2026-05-04;35000;12,5\nTOTALES;35000;\n", $csv);
        $tmp = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($tmp, (new XlsxExportador())->generar($r));
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($tmp) === true);
        $this->assertStringContainsString('Reporte de prueba', (string) $zip->getFromName('xl/worksheets/sheet1.xml') . (string) $zip->getFromName('xl/sharedStrings.xml') . (string) $zip->getFromName('docProps/core.xml'));
        $zip->close();
        unlink($tmp);
        $this->assertSame('$35.000', Reporte::texto(35000, 'clp'));
        $this->assertSame('04-05-2026', Reporte::texto('2026-05-04', 'fecha'));
    }
}

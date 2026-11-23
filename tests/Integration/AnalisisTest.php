<?php
declare(strict_types=1);

namespace EspacioLuz\Tests\Integration;

use EspacioLuz\Ia\IaException;
use EspacioLuz\Tests\Support\FakeEmisorDte;
use EspacioLuz\Tests\Support\FakeIaClient;
use PHPUnit\Framework\Attributes\Test;

/** Dashboard, indicadores, reportes con exportación, IA y auditoría. */
final class AnalisisTest extends ApiTestCase
{
    #[Test]
    public function los_indicadores_traen_formula_pregunta_y_comparacion(): void
    {
        $r = $this->request('GET', '/dashboard/indicadores', [], ['desde' => date('Y-m-d', strtotime('-90 days')), 'hasta' => date('Y-m-d')], $this->token('Gerencia'));
        $this->assertSame(200, $r->status, $r->body);
        $ind = $r->data()['data'];
        $this->assertGreaterThanOrEqual(6, count($ind));
        foreach ($ind as $i) {
            $this->assertNotEmpty($i['formula']);
            $this->assertStringEndsWith('?', $i['pregunta']);
            $this->assertArrayHasKey('anterior', $i);
        }
        $porClave = array_column($ind, null, 'clave');
        $this->assertGreaterThan(50, $porClave['tasa_asistencia']['valor']);
        $this->assertGreaterThan(0, $porClave['ingresos']['valor']);
    }

    #[Test]
    public function las_series_del_dashboard_responden_a_los_filtros(): void
    {
        $t = $this->token('Administrador');
        $todo = $this->request('GET', '/dashboard/series', [], [], $t)->data()['data'];
        foreach (['mensual', 'especialidades', 'boxes', 'dia_hora', 'estados', 'profesionales'] as $k) {
            $this->assertArrayHasKey($k, $todo);
        }
        $this->assertGreaterThanOrEqual(12, count($todo['mensual']), 'El dashboard debe mostrar 12 meses de historia.');
        $fono = $this->request('GET', '/dashboard/series', [], ['especialidad' => 'fonoaudiologia'], $t)->data()['data'];
        $this->assertSame(['Fonoaudiología'], array_column($fono['especialidades'], 'especialidad'));
        $this->assertError($this->request('GET', '/dashboard/series', [], ['desde' => '2026-05-01', 'hasta' => '2026-04-01'], $t), 422, 'VALIDATION_ERROR');
        $this->assertError($this->request('GET', '/dashboard/series', [], [], $this->token('Recepcion')), 403, 'SIN_PERMISO');
    }

    #[Test]
    public function el_reporte_operativo_se_pagina_y_oculta_pacientes_a_gerencia(): void
    {
        $r = $this->request('GET', '/reportes/atenciones', [], ['uso' => 'sesion', 'per_page' => 10, 'desde' => date('Y-m-d', strtotime('-60 days'))], $this->token('Gerencia'));
        $this->assertSame(200, $r->status, $r->body);
        $d = $r->data();
        $this->assertCount(10, $d['data']);
        $this->assertStringStartsWith('Paciente #', $d['data'][0]['paciente']);
        $this->assertGreaterThan(0, $d['totales']['precio_clp']);
        $this->assertNotEmpty($d['reporte']['columnas']);
    }

    #[Test]
    public function los_tres_formatos_de_exportacion_son_validos_y_quedan_registrados(): void
    {
        $t = $this->token('Gerencia');
        $q = ['desde' => date('Y-m-d', strtotime('-31 days')), 'hasta' => date('Y-m-d')];
        $csv = $this->request('GET', '/reportes/documentos/export', [], $q + ['formato' => 'csv'], $t);
        $this->assertSame(200, $csv->status, $csv->body);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv->body, 'CSV en UTF-8 con BOM');
        $this->assertStringContainsString('Fecha;Documento;Folio', $csv->body, 'Separador punto y coma');
        $this->assertStringContainsString('TOTALES', $csv->body);
        $xlsx = $this->request('GET', '/reportes/gerencial/export', [], $q + ['formato' => 'xlsx'], $t);
        $this->assertSame("PK", substr($xlsx->body, 0, 2));
        $this->assertStringContainsString('spreadsheetml', $xlsx->headers['Content-Type']);
        $pdf = $this->request('GET', '/reportes/atenciones/export', [], $q + ['formato' => 'pdf'], $t);
        $this->assertSame('%PDF', substr($pdf->body, 0, 4));
        $this->assertStringContainsString('attachment; filename="espacioluzdemo_atenciones_', $pdf->headers['Content-Disposition']);
        $n = (int) self::$db->pdo->query('SELECT COUNT(*) FROM exportaciones WHERE usuario_id = ' . $this->usuarios['Gerencia']['id'])->fetchColumn();
        $this->assertSame(3, $n);
    }

    #[Test]
    public function la_exportacion_respeta_los_permisos_y_exige_rango_en_el_reporte_de_documentos(): void
    {
        $this->assertError($this->request('GET', '/reportes/gerencial/export', [], ['formato' => 'csv'], $this->token('Recepcion')), 403, 'SIN_PERMISO');
        $this->assertError($this->request('GET', '/reportes/atenciones/export', [], ['formato' => 'csv'], $this->token('Clinico')), 403, 'SIN_PERMISO');
        $this->assertError($this->request('GET', '/reportes/documentos', [], [], $this->token('Administrador')), 422, 'VALIDATION_ERROR');
        $this->assertError($this->request('GET', '/reportes/atenciones/export', [], ['formato' => 'docx'], $this->token('Administrador')), 422, 'VALIDATION_ERROR');
        $this->assertError($this->request('GET', '/reportes/inventado', [], [], $this->token('Administrador')), 404, 'NO_ENCONTRADO');
    }

    #[Test]
    public function la_ia_recibe_solo_agregados_y_devuelve_un_resumen_validado(): void
    {
        $r = $this->request('POST', '/ia/resumen', ['desde' => date('Y-m-d', strtotime('-60 days')), 'hasta' => date('Y-m-d'), 'enfoque' => 'ocupacion'], [], $this->token('Gerencia'));
        $this->assertSame(200, $r->status, $r->body);
        $d = $r->data()['data'];
        $this->assertNotEmpty($d['resumen']);
        $this->assertCount(2, $d['recomendaciones']);
        $this->assertSame('modelo-simulado', $d['modelo']);
        $this->assertSame(1, $this->ia->llamadas);
        $this->assertStringNotContainsString('@', (string) $this->ia->ultimosDatos, 'No se envían correos al proveedor de IA');
        $this->assertStringNotContainsString('Paciente Adulto Prueba', (string) $this->ia->ultimosDatos);
        $this->assertStringContainsString('ocupación', (string) $this->ia->ultimosDatos);
    }

    #[Test]
    public function los_errores_de_la_ia_se_traducen_al_formato_estandar(): void
    {
        $body = ['desde' => date('Y-m-d', strtotime('-30 days')), 'hasta' => date('Y-m-d')];
        $casos = [[new IaException(IaException::TIMEOUT, 'x'), 504, 'IA_TIEMPO_AGOTADO'], [new IaException(IaException::PROVEEDOR, 'x'), 502, 'IA_ERROR'],
            [new IaException(IaException::SATURADO, 'x'), 503, 'IA_SATURADA'], [['resumen' => '', 'recomendaciones' => []], 502, 'IA_ERROR']];
        foreach ($casos as [$resp, $status, $code]) {
            $this->armar(new FakeIaClient($resp), new FakeEmisorDte());
            $this->assertError($this->request('POST', '/ia/resumen', $body, [], $this->token('Administrador')), $status, $code);
        }
        $this->armar(new FakeIaClient([], false), new FakeEmisorDte());
        $this->assertError($this->request('POST', '/ia/resumen', $body, [], $this->token('Administrador')), 503, 'IA_NO_CONFIGURADA');
        $this->assertError($this->request('POST', '/ia/resumen', ['desde' => '2025-01-01', 'hasta' => '2026-06-01'], [], $this->token('Administrador')), 422, 'VALIDATION_ERROR');
        $this->assertError($this->request('POST', '/ia/resumen', $body, [], $this->token('Recepcion')), 403, 'SIN_PERMISO');
    }

    #[Test]
    public function solo_administracion_lee_la_auditoria_con_los_campos_que_cambiaron(): void
    {
        $t = $this->token('Recepcion');
        $this->request('PUT', "/pacientes/{$this->pacienteAdulto}", ['comuna' => 'Laja'], [], $t);
        $this->assertError($this->request('GET', '/auditoria', [], [], $t), 403, 'SIN_PERMISO');
        $r = $this->request('GET', '/auditoria', [], ['tabla' => 'pacientes', 'registro_id' => (string) $this->pacienteAdulto], $this->token('Administrador'));
        $this->assertSame(200, $r->status, $r->body);
        $fila = $r->data()['data'][0];
        $this->assertSame('modificar', $fila['accion']);
        $this->assertSame('Prueba Recepcion', $fila['usuario_nombre']);
        $this->assertSame('Laja', $fila['cambios']['comuna']['despues']);
    }
}

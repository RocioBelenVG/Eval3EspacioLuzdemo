<?php
declare(strict_types=1);

namespace EspacioLuz\Tests\Integration;

use EspacioLuz\Tests\Support\FakeEmisorDte;
use EspacioLuz\Tests\Support\FakeIaClient;
use PHPUnit\Framework\Attributes\Test;

/** Cobro de sesiones con boleta demo, documentos y pagos. */
final class CobrosTest extends ApiTestCase
{
    #[Test]
    public function una_sesion_realizada_se_cobra_con_boleta_exenta_demo_pagada(): void
    {
        $id = $this->atencion($this->diaHabil(-8), '20:30:00', 'realizada', $this->pacienteAdulto, null, 'loica', 35000);
        $r = $this->request('POST', "/atenciones/$id/boleta", ['medio_pago' => 'transferencia'], [], $this->token('Recepcion'));
        $this->assertSame(201, $r->status, $r->body);
        $doc = $r->data()['data'];
        $this->assertSame('boleta', $doc['tipo']);
        $this->assertSame(35000, (int) $doc['total_clp']);
        $this->assertSame('pagado', $doc['pago_estado']);
        $this->assertStringContainsString('DEMOSTRACIÓN', $r->data()['aviso']);
        $this->assertSame(41, $this->emisor->llamadas[0]['tipo'], 'Las prestaciones de salud se boletean exentas (41).');
        $this->assertSame((int) $doc['id'], (int) self::$db->pdo->query("SELECT boleta_id FROM reservas WHERE id = $id")->fetchColumn());
        // No se puede cobrar dos veces.
        $this->assertError($this->request('POST', "/atenciones/$id/boleta", ['medio_pago' => 'efectivo'], [], $this->token('Recepcion')), 409, 'YA_COBRADA');
    }

    #[Test]
    public function el_receptor_de_un_menor_es_su_apoderado(): void
    {
        $id = $this->atencion($this->diaHabil(-8), '20:30:00', 'realizada', $this->pacienteMenor);
        $r = $this->request('POST', "/atenciones/$id/boleta", ['medio_pago' => 'efectivo', 'pagado' => false], [], $this->token('Administrador'));
        $this->assertSame(201, $r->status, $r->body);
        $this->assertSame('Apoderada Prueba', $r->data()['data']['receptor_nombre']);
        $this->assertSame('tutor', $r->data()['data']['receptor_tipo']);
        $this->assertSame('pendiente', $r->data()['data']['pago_estado']);
    }

    #[Test]
    public function no_se_cobran_arriendos_ni_atenciones_no_realizadas(): void
    {
        $t = $this->token('Recepcion');
        $arriendo = $this->atencion($this->diaHabil(-8), '20:30:00', 'realizada', null, $this->profesionalExterno, 'martin-pescador', 8000);
        $this->assertError($this->request('POST', "/atenciones/$arriendo/boleta", ['medio_pago' => 'efectivo'], [], $t), 409, 'NO_ES_SESION');
        $confirmada = $this->atencion($this->diaHabil(-9), '20:30:00', 'confirmada', $this->pacienteAdulto);
        $this->assertError($this->request('POST', "/atenciones/$confirmada/boleta", ['medio_pago' => 'efectivo'], [], $t), 409, 'TRANSICION_INVALIDA');
        $this->assertError($this->request('POST', "/atenciones/$confirmada/boleta", ['medio_pago' => 'cheque'], [], $t), 422, 'VALIDATION_ERROR');
    }

    #[Test]
    public function si_el_motor_dte_falla_no_queda_ningun_cobro_a_medias(): void
    {
        $this->armar(new FakeIaClient(), new FakeEmisorDte(false));
        $id = $this->atencion($this->diaHabil(-8), '20:30:00', 'realizada', $this->pacienteAdulto);
        $this->assertError($this->request('POST', "/atenciones/$id/boleta", ['medio_pago' => 'efectivo'], [], $this->token('Recepcion')), 502, 'DTE_ERROR');
        $this->assertNull(self::$db->pdo->query("SELECT boleta_id FROM reservas WHERE id = $id")->fetchColumn() ?: null);
    }

    #[Test]
    public function los_documentos_se_filtran_y_los_pagos_respetan_el_saldo(): void
    {
        $t = $this->token('Administrador');
        $l = $this->request('GET', '/documentos', [], ['tipo' => 'factura', 'pago_estado' => 'pendiente', 'per_page' => 10], $t)->data();
        $this->assertNotEmpty($l['data']);
        $this->assertSame(['factura'], array_values(array_unique(array_column($l['data'], 'tipo'))));
        $doc = $l['data'][0];
        $saldo = (int) $doc['saldo_clp'];
        $this->assertError($this->request('POST', "/documentos/{$doc['id']}/pagos", ['monto' => $saldo + 1, 'medio' => 'transferencia'], [], $t), 422, 'VALIDATION_ERROR');
        $this->assertError($this->request('POST', "/documentos/{$doc['id']}/pagos", ['monto' => 1000, 'medio' => 'transferencia', 'fecha' => date('Y-m-d', strtotime('+3 days'))], [], $t), 422, 'VALIDATION_ERROR');
        $r = $this->request('POST', "/documentos/{$doc['id']}/pagos", ['monto' => $saldo, 'medio' => 'transferencia', 'referencia' => 'Prueba'], [], $t);
        $this->assertSame(201, $r->status, $r->body);
        $this->assertSame('pagado', $r->data()['data']['pago_estado']);
        $this->assertSame(0, (int) $r->data()['data']['saldo_clp']);
        $this->assertError($this->request('POST', "/documentos/{$doc['id']}/pagos", ['monto' => 1, 'medio' => 'efectivo'], [], $t), 409, 'DOCUMENTO_PAGADO');
    }

    #[Test]
    public function la_gerencia_consulta_documentos_pero_no_registra_pagos_y_el_area_clinica_no_los_ve(): void
    {
        $id = (int) self::$db->pdo->query("SELECT id FROM boletas WHERE pago_estado <> 'pagado' LIMIT 1")->fetchColumn();
        $this->assertSame(200, $this->request('GET', "/documentos/$id", [], [], $this->token('Gerencia'))->status);
        $this->assertError($this->request('POST', "/documentos/$id/pagos", ['monto' => 1, 'medio' => 'efectivo'], [], $this->token('Gerencia')), 403, 'SIN_PERMISO');
        $this->assertError($this->request('GET', '/documentos', [], [], $this->token('Clinico')), 403, 'SIN_PERMISO');
    }
}

<?php
declare(strict_types=1);

namespace EspacioLuz\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;

/** Agenda: listado, alta con validaciones, flujo de estados, roles y auditoría. */
final class AtencionesTest extends ApiTestCase
{
    private function nueva(array $extra = []): array
    {
        return $extra + ['box' => 'loica', 'profesional_id' => $this->profesionalClinico, 'paciente_id' => $this->pacienteAdulto,
            'fecha' => $this->diaHabil(45), 'hora' => '10:00', 'tipo_atencion' => 'intervencion', 'precio_clp' => 35000];
    }

    #[Test]
    public function el_listado_pagina_filtra_y_ordena(): void
    {
        $r = $this->request('GET', '/atenciones', [], ['estado' => 'realizada', 'per_page' => 5, 'page' => 2, 'sort' => 'fecha'], $this->token('Recepcion'));
        $this->assertSame(200, $r->status, $r->body);
        $d = $r->data();
        $this->assertCount(5, $d['data']);
        $this->assertSame(2, $d['meta']['page']);
        $this->assertGreaterThan(500, $d['meta']['total'], 'La entidad principal debe tener más de 500 registros.');
        $fechas = array_column($d['data'], 'fecha');
        $ordenadas = $fechas;
        sort($ordenadas);
        $this->assertSame($ordenadas, $fechas);
        $this->assertSame(['realizada'], array_values(array_unique(array_column($d['data'], 'estado'))));
    }

    #[Test]
    public function filtros_invalidos_responden_422(): void
    {
        $r = $this->request('GET', '/atenciones', [], ['estado' => 'inventado', 'per_page' => 500, 'desde' => '2026-02-31'], $this->token('Recepcion'));
        $this->assertError($r, 422, 'VALIDATION_ERROR');
    }

    #[Test]
    public function recepcion_agenda_una_sesion_y_queda_auditada_a_su_nombre(): void
    {
        $r = $this->request('POST', '/atenciones', $this->nueva(), [], $this->token('Recepcion'));
        $this->assertSame(201, $r->status, $r->body);
        $a = $r->data()['data'];
        $this->assertSame('pendiente', $a['estado']);
        $this->assertSame('sesion', $a['uso']);
        $this->assertSame(['confirmada', 'realizada', 'cancelada', 'no_show'], $a['transiciones']);
        $aud = self::$db->pdo->query("SELECT accion, usuario_id, origen FROM auditoria WHERE tabla = 'reservas' AND registro_id = '{$a['id']}' ORDER BY id DESC LIMIT 1")->fetch();
        $this->assertSame('crear', $aud['accion']);
        $this->assertSame($this->usuarios['Recepcion']['id'], (int) $aud['usuario_id']);
        $this->assertSame('api', $aud['origen']);
    }

    #[Test]
    public function las_reglas_de_calendario_se_validan_en_la_api(): void
    {
        $t = $this->token('Recepcion');
        $domingo = date('Y-m-d', strtotime('next sunday +4 weeks'));
        $casos = [
            ['fecha' => $this->diaHabil(-10)],                     // pasado
            ['fecha' => $domingo],                                 // domingo
            ['hora' => '22:00'],                                   // fuera de horario
            ['hora' => '10:15'],                                   // no es bloque de 30 min
            ['box' => 'no-existe'],                                // box inexistente
            ['profesional_id' => 999999],                          // profesional inexistente
            ['duracion_min' => 5],                                 // duración fuera de rango
        ];
        foreach ($casos as $c) {
            $r = $this->request('POST', '/atenciones', $this->nueva($c), [], $t);
            $this->assertError($r, 422, 'VALIDATION_ERROR');
            $this->assertNotEmpty($r->data()['error']['details'][0]['message']);
        }
    }

    #[Test]
    public function un_box_lleno_o_un_profesional_ocupado_responden_409(): void
    {
        $t = $this->token('Administrador');
        $base = $this->nueva(['paciente_id' => null, 'tipo_atencion' => null, 'hora' => '11:00']);
        $this->assertSame(201, $this->request('POST', '/atenciones', $base, [], $t)->status);
        $this->assertError($this->request('POST', '/atenciones', $base, [], $t), 409, 'PROFESIONAL_OCUPADO');
        $this->assertSame(201, $this->request('POST', '/atenciones', ['profesional_id' => $this->profesionalExterno] + $base, [], $t)->status);
        $this->assertError($this->request('POST', '/atenciones', ['profesional_id' => $this->profesionalExterno, 'hora' => '11:00', 'box' => 'loica'] + $this->nueva(['paciente_id' => null, 'tipo_atencion' => null]), [], $t), 409, 'PROFESIONAL_OCUPADO');
    }

    #[Test]
    public function el_flujo_de_estados_impide_transiciones_invalidas(): void
    {
        $t = $this->token('Recepcion');
        $id = $this->request('POST', '/atenciones', $this->nueva(), [], $t)->data()['data']['id'];
        $this->assertSame('confirmada', $this->request('PATCH', "/atenciones/$id/estado", ['estado' => 'confirmada'], [], $t)->data()['data']['estado']);
        // Futura: no puede quedar realizada.
        $this->assertError($this->request('PATCH', "/atenciones/$id/estado", ['estado' => 'realizada'], [], $t), 422, 'VALIDATION_ERROR');
        // Confirmada → pendiente no existe en el flujo.
        $this->assertError($this->request('PATCH', "/atenciones/$id/estado", ['estado' => 'pendiente'], [], $t), 409, 'TRANSICION_INVALIDA');
        // Cancelar y reactivar.
        $this->assertSame('cancelada', $this->request('PATCH', "/atenciones/$id/estado", ['estado' => 'cancelada', 'motivo' => 'Aviso de la familia'], [], $t)->data()['data']['estado']);
        $this->assertSame('pendiente', $this->request('PATCH', "/atenciones/$id/estado", ['estado' => 'pendiente'], [], $t)->data()['data']['estado']);
        $mov = self::$db->pdo->query("SELECT GROUP_CONCAT(accion ORDER BY id) FROM reservas_movimientos WHERE reserva_id = $id")->fetchColumn();
        $this->assertSame('cancelacion,reactivacion', $mov);
    }

    #[Test]
    public function una_atencion_cerrada_no_admite_cambios_ni_en_la_base_de_datos(): void
    {
        $id = $this->atencion($this->diaHabil(-15), '20:30:00', 'realizada', $this->pacienteAdulto);
        $this->assertError($this->request('PATCH', "/atenciones/$id/estado", ['estado' => 'pendiente'], [], $this->token('Administrador')), 409, 'TRANSICION_INVALIDA');
        // Tercer nivel: el disparador de la base rechaza el cambio aunque alguien se salte la API.
        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('TRANSICION_INVALIDA');
        self::$db->pdo->exec("UPDATE reservas SET estado = 'pendiente' WHERE id = $id");
    }

    #[Test]
    public function una_atencion_cobrada_no_se_cancela_desde_la_api(): void
    {
        $id = $this->atencion($this->diaHabil(-12), '20:30:00', 'realizada', $this->pacienteAdulto);
        $t = $this->token('Recepcion');
        $this->assertSame(201, $this->request('POST', "/atenciones/$id/boleta", ['medio_pago' => 'tarjeta'], [], $t)->status);
        $futura = $this->request('POST', '/atenciones', $this->nueva(), [], $t)->data()['data']['id'];
        self::$db->pdo->exec("UPDATE reservas SET boleta_id = (SELECT boleta_id FROM (SELECT boleta_id FROM reservas WHERE id = $id) x) WHERE id = $futura");
        $this->assertError($this->request('PATCH', "/atenciones/$futura/estado", ['estado' => 'cancelada'], [], $t), 409, 'COBRO_ASOCIADO');
    }

    #[Test]
    public function gerencia_lee_pero_no_agenda_y_el_area_clinica_solo_ve_lo_suyo(): void
    {
        $this->assertError($this->request('POST', '/atenciones', $this->nueva(), [], $this->token('Gerencia')), 403, 'SIN_PERMISO');
        $ajena = $this->atencion($this->diaHabil(-20), '20:30:00', 'confirmada', null, $this->profesionalExterno, 'martin-pescador', 8000);
        $propia = $this->atencion($this->diaHabil(-20), '20:30:00', 'confirmada', $this->pacienteAdulto);
        $tc = $this->token('Clinico');
        $lista = $this->request('GET', '/atenciones', [], ['per_page' => 100], $tc)->data();
        $this->assertSame([$this->profesionalClinico], array_values(array_unique(array_column($lista['data'], 'profesional_id'))));
        $this->assertError($this->request('GET', "/atenciones/$ajena", [], [], $tc), 404, 'NO_ENCONTRADO');      // BOLA
        $this->assertError($this->request('PATCH', "/atenciones/$propia/estado", ['estado' => 'cancelada'], [], $tc), 403, 'SIN_PERMISO');
        $this->assertSame('realizada', $this->request('PATCH', "/atenciones/$propia/estado", ['estado' => 'realizada'], [], $tc)->data()['data']['estado']);
    }

    #[Test]
    public function reprogramar_mueve_la_atencion_y_deja_el_movimiento(): void
    {
        $t = $this->token('Recepcion');
        $id = $this->request('POST', '/atenciones', $this->nueva(), [], $t)->data()['data']['id'];
        $nueva = $this->diaHabil(50);
        $r = $this->request('PATCH', "/atenciones/$id", ['fecha' => $nueva, 'hora' => '15:30', 'box' => '7-colores'], [], $t);
        $this->assertSame(200, $r->status, $r->body);
        $this->assertSame($nueva, $r->data()['data']['fecha']);
        $this->assertSame('15:30', $r->data()['data']['hora']);
        $this->assertSame('7-colores', $r->data()['data']['box_slug']);
        $this->assertSame('reagenda', $r->data()['data']['movimientos'][0]['accion']);
    }
}

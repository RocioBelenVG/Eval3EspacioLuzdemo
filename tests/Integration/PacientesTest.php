<?php
declare(strict_types=1);

namespace EspacioLuz\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;

/** Registro de pacientes: validación en API y base de datos, privacidad por rol. */
final class PacientesTest extends ApiTestCase
{
    private function datos(array $extra = []): array
    {
        return $extra + ['nombre' => 'Agustina Rojas Soto', 'rut' => $this->rutUnico(false), 'email' => 'agustina.rojas@example.com',
            'telefono' => '+56 9 5555 1234', 'fecha_nacimiento' => '1988-03-14', 'sexo' => 'mujer', 'comuna' => 'Los Ángeles',
            'consentimiento' => true];
    }

    #[Test]
    public function recepcion_registra_un_paciente_con_rut_formateado(): void
    {
        $r = $this->request('POST', '/pacientes', $this->datos(), [], $this->token('Recepcion'));
        $this->assertSame(201, $r->status, $r->body);
        $p = $r->data()['data'];
        $this->assertMatchesRegularExpression('/^\d{1,2}\.\d{3}\.\d{3}-[\dK]$/', $p['rut']);
        $this->assertSame(1, (int) $p['consentimiento']);
        $this->assertArrayNotHasKey('notas', $p);
    }

    #[Test]
    public function reglas_del_paciente_responden_422_con_el_campo_afectado(): void
    {
        $t = $this->token('Recepcion');
        $casos = [
            'rut' => ['rut' => '12345678-0'],
            'fecha_nacimiento' => ['fecha_nacimiento' => date('Y-m-d', strtotime('+1 day'))],
            'consentimiento' => ['consentimiento' => false],
            'apoderado' => ['fecha_nacimiento' => date('Y-m-d', strtotime('-6 years'))],
            'email' => ['email' => 'correo-malo'],
            'nombre' => ['nombre' => 'X1'],
        ];
        foreach ($casos as $campo => $cambio) {
            $r = $this->request('POST', '/pacientes', $this->datos($cambio), [], $t);
            $this->assertError($r, 422, 'VALIDATION_ERROR');
            $this->assertContains($campo, array_column($r->data()['error']['details'], 'field'), $r->body);
        }
    }

    #[Test]
    public function un_rut_repetido_responde_409(): void
    {
        $t = $this->token('Administrador');
        $d = $this->datos();
        $this->assertSame(201, $this->request('POST', '/pacientes', $d, [], $t)->status);
        $this->assertError($this->request('POST', '/pacientes', ['email' => 'otro@example.com'] + $d, [], $t), 409, 'RUT_DUPLICADO');
    }

    #[Test]
    public function la_api_ignora_campos_no_permitidos(): void
    {
        $r = $this->request('POST', '/pacientes', $this->datos(['id' => 1, 'activo' => false, 'consentimiento_fecha' => '2000-01-01']), [], $this->token('Recepcion'));
        $this->assertSame(201, $r->status, $r->body);
        $p = $r->data()['data'];
        $this->assertNotSame(1, (int) $p['id']);
        $this->assertSame(1, (int) $p['activo']);
    }

    #[Test]
    public function la_base_de_datos_rechaza_un_correo_invalido_aunque_se_salte_la_api(): void
    {
        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('chk_pac_email');
        self::$db->pdo->exec("UPDATE pacientes SET email = 'sin-arroba' WHERE id = {$this->pacienteAdulto}");
    }

    #[Test]
    public function la_busqueda_pagina_y_el_area_clinica_ve_solo_sus_pacientes(): void
    {
        $r = $this->request('GET', '/pacientes', [], ['q' => 'Prueba', 'per_page' => 50], $this->token('Recepcion'))->data();
        $this->assertContains($this->pacienteAdulto, array_map('intval', array_column($r['data'], 'id')));
        $this->assertSame(0, count($this->request('GET', '/pacientes', [], ['per_page' => 50], $this->token('Clinico'))->data()['data']));
        $this->atencion($this->diaHabil(-5), '20:30:00', 'realizada', $this->pacienteAdulto);
        $propios = $this->request('GET', '/pacientes', [], ['per_page' => 50], $this->token('Clinico'))->data()['data'];
        $this->assertSame([$this->pacienteAdulto], array_map('intval', array_column($propios, 'id')));
        $this->assertError($this->request('GET', "/pacientes/{$this->pacienteMenor}", [], [], $this->token('Clinico')), 404, 'NO_ENCONTRADO');
        $this->assertError($this->request('GET', '/pacientes', [], [], $this->token('Gerencia')), 403, 'SIN_PERMISO');
    }

    #[Test]
    public function actualizar_un_paciente_valida_y_guarda_los_cambios(): void
    {
        $t = $this->token('Recepcion');
        $r = $this->request('PUT', "/pacientes/{$this->pacienteAdulto}", ['telefono' => '+56 9 4444 3333', 'comuna' => 'Mulchén'], [], $t);
        $this->assertSame(200, $r->status, $r->body);
        $this->assertSame('Mulchén', $r->data()['data']['comuna']);
        $this->assertError($this->request('PUT', '/pacientes/999999999', ['comuna' => 'Laja'], [], $t), 404, 'NO_ENCONTRADO');
    }
}

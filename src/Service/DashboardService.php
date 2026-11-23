<?php
declare(strict_types=1);

namespace EspacioLuz\Service;

use EspacioLuz\Exception\ApiException;
use EspacioLuz\Repository\CatalogoRepository;
use EspacioLuz\Repository\DashboardRepository;
use EspacioLuz\Validation\Validator;

/**
 * Indicadores (BI I) y series del dashboard (BI II), calculados desde los datos del
 * sistema con los mismos filtros: rango de fechas, especialidad, box y profesional.
 * Cada indicador declara su fórmula y la pregunta de negocio que responde, y se compara
 * con el período anterior de igual duración.
 */
final class DashboardService
{
    /** Máximo de días por consulta (protege el rendimiento: OWASP API4). */
    public const RANGO_MAX = 731;

    public function __construct(
        private readonly DashboardRepository $repo,
        private readonly CatalogoRepository $catalogo,
        private readonly ?\Closure $hoy = null,
    ) {
    }

    /** Filtros validados; por defecto, los últimos 12 meses cerrados hasta hoy. */
    public function filtros(array $query): array
    {
        $f = Validator::check($query, [
            'desde' => ['date'],
            'hasta' => ['date'],
            'especialidad' => ['string', 'max:60', 'regex:/^[a-z0-9-]+$/'],
            'box' => ['string', 'max:60', 'regex:/^[a-z0-9-]+$/'],
            'profesional_id' => ['int', 'min:1'],
        ]);
        $hoy = $this->hoy ? ($this->hoy)() : date('Y-m-d');
        $f['hasta'] ??= $hoy;
        $f['desde'] ??= date('Y-m-d', strtotime($f['hasta'] . ' -1 year +1 day'));
        if ($f['desde'] > $f['hasta']) {
            throw ApiException::field('hasta', 'La fecha final debe ser igual o posterior a la inicial.');
        }
        if ((strtotime($f['hasta']) - strtotime($f['desde'])) / 86400 > self::RANGO_MAX) {
            throw ApiException::field('desde', 'El rango no puede superar los 2 años.');
        }
        return array_filter($f, fn ($v) => $v !== null && $v !== '');
    }

    /** Período anterior de la misma duración. */
    public static function anterior(array $f): array
    {
        $dias = (int) round((strtotime($f['hasta']) - strtotime($f['desde'])) / 86400) + 1;
        $hasta = date('Y-m-d', strtotime($f['desde'] . ' -1 day'));
        return ['desde' => date('Y-m-d', strtotime($hasta . ' -' . ($dias - 1) . ' days')), 'hasta' => $hasta] + $f;
    }

    /** @return list<array{clave: string, nombre: string, pregunta: string, formula: string, valor: float|int|null, anterior: float|int|null, unidad: string, mejor: string}> */
    public function indicadores(array $f): array
    {
        $act = $this->calcular($f);
        $ant = $this->calcular(self::anterior($f));
        $ret = $this->repo->retencion($f, self::anterior($f)['desde'], self::anterior($f)['hasta']);
        $def = [
            ['tasa_asistencia', 'Tasa de asistencia', '¿Los pacientes llegan a las sesiones que agendan?',
                'sesiones realizadas ÷ (sesiones realizadas + inasistencias) × 100', '%', 'alto'],
            ['tasa_cancelacion', 'Tasa de cancelación', '¿Qué parte de la agenda se pierde por cancelaciones?',
                'atenciones canceladas ÷ atenciones agendadas × 100', '%', 'bajo'],
            ['ocupacion_boxes', 'Ocupación de boxes', '¿Estamos aprovechando la capacidad instalada de los boxes?',
                'horas-box con al menos una reserva vigente ÷ (horas de apertura × días hábiles × boxes activos) × 100', '%', 'alto'],
            ['ingresos', 'Ingresos netos', '¿Cuánto factura el centro en el período?',
                'Σ boletas y facturas emitidas − Σ notas de crédito + Σ notas de débito', 'CLP', 'alto'],
            ['ticket_sesion', 'Ticket promedio por sesión', '¿Cuánto ingresa en promedio cada sesión cobrada?',
                'ingresos de boletas a pacientes ÷ sesiones cobradas', 'CLP', 'alto'],
            ['pacientes_nuevos', 'Pacientes nuevos', '¿Cuántos pacientes se suman al centro?',
                'pacientes cuya primera atención realizada cae en el período', 'pacientes', 'alto'],
            ['retencion', 'Retención de pacientes', '¿Los pacientes continúan su tratamiento?',
                'pacientes atendidos en el período anterior que vuelven en este ÷ pacientes atendidos en el período anterior × 100', '%', 'alto'],
            ['dias_pago', 'Días promedio de pago de facturas', '¿Cuánto demoran los clientes en pagar el arriendo de box?',
                'promedio de (fecha de pago − fecha de emisión) de las facturas pagadas', 'días', 'bajo'],
            ['por_cobrar', 'Cuentas por cobrar', '¿Cuánto dinero facturado falta recaudar?',
                'Σ saldos de documentos emitidos y no pagados al cierre del período', 'CLP', 'bajo'],
            ['anticipacion', 'Anticipación de reserva', '¿Con cuántos días de anticipación se agenda?',
                'promedio de (fecha de la atención − fecha de creación de la reserva)', 'días', 'alto'],
        ];
        $out = [];
        foreach ($def as [$clave, $nombre, $pregunta, $formula, $unidad, $mejor]) {
            $valor = $clave === 'retencion'
                ? ($ret['anteriores'] > 0 ? round(100 * $ret['vuelven'] / $ret['anteriores'], 1) : null)
                : $act[$clave];
            $out[] = ['clave' => $clave, 'nombre' => $nombre, 'pregunta' => $pregunta, 'formula' => $formula,
                'valor' => $valor, 'anterior' => $clave === 'retencion' ? null : $ant[$clave], 'unidad' => $unidad, 'mejor' => $mejor];
        }
        return $out;
    }

    /** Valores crudos de los indicadores para un rango. */
    private function calcular(array $f): array
    {
        $e = $this->repo->estados($f);
        $c = $this->repo->cobranza($f['desde'], $f['hasta']);
        $sesAsist = (int) ($e['sesiones_realizadas'] ?? 0) + (int) ($e['sesiones_no_show'] ?? 0);
        $agendadas = (int) ($e['agendadas'] ?? 0);
        $capacidad = $this->horasDisponibles($f) * $this->repo->totalBoxesActivos($f['box'] ?? null);
        $cobradas = (int) ($e['sesiones_cobradas'] ?? 0);
        return [
            'tasa_asistencia' => $sesAsist > 0 ? round(100 * (int) $e['sesiones_realizadas'] / $sesAsist, 1) : null,
            'tasa_cancelacion' => $agendadas > 0 ? round(100 * (int) $e['canceladas'] / $agendadas, 1) : null,
            'ocupacion_boxes' => $capacidad > 0 ? round(100 * $this->repo->slotsOcupados($f) / $capacidad, 1) : null,
            'ingresos' => $this->repo->ingresos($f),
            'ticket_sesion' => $cobradas > 0 ? (int) round($this->repo->ingresosSesiones($f) / $cobradas) : null,
            'pacientes_nuevos' => $this->repo->pacientesNuevos($f),
            'dias_pago' => isset($c['dias_pago_facturas']) ? (float) $c['dias_pago_facturas'] : null,
            'por_cobrar' => (int) ($c['por_cobrar'] ?? 0),
            'anticipacion' => isset($e['anticipacion_promedio']) ? (float) $e['anticipacion_promedio'] : null,
        ];
    }

    /** Horas de apertura del rango: días hábiles (sin domingos ni feriados cerrados) × horas del día. */
    public function horasDisponibles(array $f): float
    {
        $apertura = $this->repo->horasApertura();
        $cerrados = array_flip($this->catalogo->feriadosCerrados($f['desde'], $f['hasta']));
        $total = 0.0;
        for ($t = strtotime($f['desde']), $fin = strtotime($f['hasta']); $t <= $fin; $t += 86400) {
            $dia = (int) date('N', $t);
            if ($dia === 7 || isset($cerrados[date('Y-m-d', $t)])) {
                continue;
            }
            $total += $apertura[$dia] ?? 0.0;
        }
        return $total;
    }

    /** Series del dashboard: una consulta de agregación por gráfico. */
    public function series(array $f): array
    {
        $porBox = $this->repo->porBox($f);
        $horas = $this->horasDisponibles($f);
        foreach ($porBox as &$b) {
            $b['ocupacion'] = $horas > 0 ? round(100 * (int) $b['horas_ocupadas'] / $horas, 1) : null;
        }
        $e = $this->repo->estados($f);
        return [
            'mensual' => $this->repo->mensual($f),
            'especialidades' => $this->repo->porEspecialidad($f),
            'boxes' => $porBox,
            'dia_hora' => $this->repo->diaHora($f),
            'estados' => [
                ['estado' => 'realizada', 'etiqueta' => 'Realizada', 'n' => (int) ($e['realizadas'] ?? 0)],
                ['estado' => 'confirmada', 'etiqueta' => 'Confirmada', 'n' => (int) ($e['confirmadas'] ?? 0)],
                ['estado' => 'pendiente', 'etiqueta' => 'Pendiente', 'n' => (int) ($e['pendientes'] ?? 0)],
                ['estado' => 'cancelada', 'etiqueta' => 'Cancelada', 'n' => (int) ($e['canceladas'] ?? 0)],
                ['estado' => 'no_show', 'etiqueta' => 'No asistió', 'n' => (int) ($e['no_show'] ?? 0)],
            ],
            'profesionales' => $this->repo->porProfesional($f),
        ];
    }
}

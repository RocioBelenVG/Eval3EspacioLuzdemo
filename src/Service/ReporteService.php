<?php
declare(strict_types=1);

namespace EspacioLuz\Service;

use EspacioLuz\Domain\Rol;
use EspacioLuz\Exception\ApiException;
use EspacioLuz\Export\Exportador;
use EspacioLuz\Report\Reporte;
use EspacioLuz\Repository\AtencionRepository;
use EspacioLuz\Repository\AuditoriaRepository;
use EspacioLuz\Repository\DashboardRepository;
use EspacioLuz\Repository\DocumentoRepository;
use EspacioLuz\Security\AuthUser;
use EspacioLuz\Validation\Validator;

/**
 * Los tres reportes del sistema (mismos datos en pantalla, CSV, Excel, PDF e impresión):
 *   atenciones  · operativo (detalle de cada atención, filtrable y paginado)
 *   gerencial   · resumen mensual con totales, indicadores y gráfico
 *   documentos  · parametrizable por rango de fechas: boletas, facturas, pagos y saldos
 * Cada reporte declara qué roles lo pueden ver y exportar (OWASP API5).
 */
final class ReporteService
{
    /** Máximo de filas por exportación (OWASP API4): más que eso, se pide acotar los filtros. */
    public const TOPE_EXPORTACION = 20000;

    public const REPORTES = [
        'atenciones' => ['titulo' => 'Reporte operativo de atenciones', 'roles' => ['admin', 'editor', 'gerencia']],
        'gerencial' => ['titulo' => 'Reporte gerencial mensual', 'roles' => ['admin', 'gerencia']],
        'documentos' => ['titulo' => 'Reporte de documentos y cobranza', 'roles' => ['admin', 'gerencia']],
    ];

    /** @param array<string, Exportador> $exportadores formato => estrategia (patrón Strategy) */
    public function __construct(
        private readonly AtencionService $atenciones,
        private readonly AtencionRepository $atencionRepo,
        private readonly DocumentoService $documentos,
        private readonly DocumentoRepository $documentoRepo,
        private readonly DashboardService $dashboard,
        private readonly DashboardRepository $dashboardRepo,
        private readonly AuditoriaRepository $auditoria,
        private readonly array $exportadores,
    ) {
    }

    public function autorizar(AuthUser $u, string $clave): void
    {
        $def = self::REPORTES[$clave] ?? throw ApiException::notFound('El reporte no existe. Disponibles: ' . implode(', ', array_keys(self::REPORTES)) . '.');
        if (!in_array($u->rol->value, $def['roles'], true)) {
            throw ApiException::forbidden('Su rol no tiene acceso a este reporte.');
        }
    }

    /** Datos paginados para la interfaz (mismas columnas que la exportación). */
    public function datos(AuthUser $u, string $clave, array $query): array
    {
        $this->autorizar($u, $clave);
        [$page, $perPage] = Validator::paginacion($query);
        $columnas = $this->columnas($clave);
        switch ($clave) {
            case 'atenciones':
                $f = $this->atenciones->filtros($query);
                $r = $this->atencionRepo->listar($f, $f['sort'] ?? 'fecha', $page, $perPage);
                $r['data'] = array_map(fn ($x) => $this->filaAtencion($x, $u), $r['data']);
                $r['totales'] = ['filas' => $r['meta']['total'], 'precio_clp' => $this->sumaPrecio($f)];
                break;
            case 'documentos':
                $f = $this->filtrosDocumentos($query);
                $r = $this->documentoRepo->listar($f, $f['sort'] ?? 'fecha', $page, $perPage);
                $r['data'] = array_map(fn ($x) => $this->filaDocumento($x), $r['data']);
                $r['totales'] = $r['resumen'];
                unset($r['resumen']);
                break;
            default:
                $rep = $this->construir($u, $clave, $query);
                $r = ['data' => $rep->filas, 'meta' => ['page' => 1, 'per_page' => count($rep->filas), 'total' => count($rep->filas), 'total_pages' => 1],
                    'totales' => $rep->totales, 'grafico' => $rep->grafico, 'nota' => $rep->nota];
        }
        $r['reporte'] = ['clave' => $clave, 'titulo' => self::REPORTES[$clave]['titulo'],
            'columnas' => array_map(fn ($k, $c) => ['clave' => $k, 'etiqueta' => $c[0], 'tipo' => $c[1]], array_keys($columnas), $columnas)];
        return $r;
    }

    /** @return array{0: Reporte, 1: Exportador} */
    public function exportar(AuthUser $u, string $clave, array $query, string $ip): array
    {
        $this->autorizar($u, $clave);
        $formato = Validator::check($query, ['formato' => ['required', 'in:' . implode(',', array_keys($this->exportadores))]])['formato'];
        $rep = $this->construir($u, $clave, $query);
        $filtros = array_diff_key($query, ['formato' => 1, 'page' => 1, 'per_page' => 1, 'access_token' => 1]);
        $this->auditoria->registrarExportacion($u->id, $clave, $formato, $filtros, count($rep->filas), $ip);
        return [$rep, $this->exportadores[$formato]];
    }

    /** Reporte completo (sin paginar) listo para exportar o imprimir. */
    public function construir(AuthUser $u, string $clave, array $query): Reporte
    {
        $emisor = $u->nombre . ' (' . $u->rol->etiqueta() . ')';
        $empresa = 'Espacio Luz Demo · Centro terapéutico';
        $columnas = $this->columnas($clave);
        if ($clave === 'atenciones') {
            $f = $this->atenciones->filtros($query);
            $this->tope($this->atencionRepo->contar($f));
            $filas = array_map(fn ($x) => $this->filaAtencion($x, $u), $this->atencionRepo->todas($f, $f['sort'] ?? 'fecha', self::TOPE_EXPORTACION));
            $totales = ['precio_clp' => array_sum(array_column($filas, 'precio_clp'))];
            return new Reporte($clave, self::REPORTES[$clave]['titulo'], $columnas, $filas, $totales, $this->filtrosLegibles($f), $empresa, $emisor);
        }
        if ($clave === 'documentos') {
            $f = $this->filtrosDocumentos($query);
            $filas = array_map(fn ($x) => $this->filaDocumento($x), $this->documentoRepo->todos($f, $f['sort'] ?? 'fecha', self::TOPE_EXPORTACION + 1));
            $this->tope(count($filas));
            $totales = [];
            foreach (['total_clp', 'nc_clp', 'pagado_clp', 'saldo_clp'] as $k) {
                $totales[$k] = array_sum(array_column($filas, $k));
            }
            return new Reporte($clave, self::REPORTES[$clave]['titulo'], $columnas, $filas, $totales, $this->filtrosLegibles($f), $empresa, $emisor);
        }
        // Gerencial: resumen mensual + indicadores del período.
        $f = $this->dashboard->filtros($query);
        $filas = $this->dashboardRepo->mensual($f);
        $tot = ['agendadas' => 0, 'realizadas' => 0, 'canceladas' => 0, 'no_show' => 0, 'horas_box' => 0, 'pacientes' => null, 'documentos' => 0, 'ingresos' => 0];
        foreach ($filas as $fila) {
            foreach (['agendadas', 'realizadas', 'canceladas', 'no_show', 'horas_box', 'documentos', 'ingresos'] as $k) {
                $tot[$k] += (float) $fila[$k];
            }
        }
        unset($tot['pacientes']);
        $ind = $this->dashboard->indicadores($f);
        // Asistencia del período: la misma del indicador (solo sesiones con paciente).
        $tot['tasa_asistencia'] = array_column($ind, null, 'clave')['tasa_asistencia']['valor'] ?? null;
        $nota = 'Indicadores del período: ' . implode(' · ', array_map(fn ($i) => $i['nombre'] . ': ' . self::valorIndicador($i), $ind)) . '.';
        $grafico = ['titulo' => 'Ingresos netos por mes (CLP)', 'etiquetas' => array_map(fn ($x) => substr($x['mes'], 2), $filas),
            'valores' => array_map(fn ($x) => (float) $x['ingresos'], $filas), 'tipo' => 'clp'];
        return new Reporte('gerencial', self::REPORTES['gerencial']['titulo'], $columnas, $filas, $tot, $this->filtrosLegibles($f), $empresa, $emisor, [], $grafico, $nota);
    }

    public static function valorIndicador(array $i): string
    {
        if ($i['valor'] === null) {
            return 'sin datos';
        }
        return match ($i['unidad']) {
            'CLP' => '$' . number_format((float) $i['valor'], 0, ',', '.'),
            '%' => number_format((float) $i['valor'], 1, ',', '.') . ' %',
            default => number_format((float) $i['valor'], $i['unidad'] === 'días' ? 1 : 0, ',', '.') . ' ' . $i['unidad'],
        };
    }

    private function columnas(string $clave): array
    {
        return match ($clave) {
            'atenciones' => [
                'fecha' => ['Fecha', 'fecha', 9], 'hora' => ['Hora', 'texto', 5], 'box' => ['Box', 'texto', 11],
                'profesional' => ['Profesional', 'texto', 17], 'especialidad' => ['Especialidad', 'texto', 12],
                'paciente' => ['Paciente / uso', 'texto', 17], 'tipo' => ['Tipo', 'texto', 11], 'estado' => ['Estado', 'texto', 9],
                'precio_clp' => ['Precio', 'clp', 9], 'cobrada' => ['Cobrada', 'texto', 6],
            ],
            'documentos' => [
                'fecha' => ['Fecha', 'fecha', 9], 'documento' => ['Documento', 'texto', 12], 'folio' => ['Folio', 'entero', 6],
                'receptor_nombre' => ['Receptor', 'texto', 18], 'empresa' => ['Emisor', 'texto', 14], 'total_clp' => ['Total', 'clp', 9],
                'nc_clp' => ['Notas crédito', 'clp', 9], 'pagado_clp' => ['Pagado', 'clp', 9], 'saldo_clp' => ['Saldo', 'clp', 9],
                'pago' => ['Estado de pago', 'texto', 10], 'vence_en' => ['Vence', 'fecha', 8], 'dias_pago' => ['Días pago', 'entero', 6],
            ],
            default => [
                'mes' => ['Mes', 'texto', 7], 'agendadas' => ['Agendadas', 'entero', 8], 'realizadas' => ['Realizadas', 'entero', 8],
                'canceladas' => ['Canceladas', 'entero', 8], 'no_show' => ['No asistió', 'entero', 8], 'tasa_asistencia' => ['Asistencia', 'pct', 8],
                'horas_box' => ['Horas box', 'decimal', 8], 'pacientes' => ['Pacientes', 'entero', 8], 'documentos' => ['Documentos', 'entero', 8],
                'ingresos' => ['Ingresos netos', 'clp', 11],
            ],
        };
    }

    private function filaAtencion(array $a, AuthUser $u): array
    {
        $estados = ['pendiente' => 'Pendiente', 'confirmada' => 'Confirmada', 'realizada' => 'Realizada', 'cancelada' => 'Cancelada', 'no_show' => 'No asistió'];
        $tipos = ['evaluacion' => 'Evaluación', 'intervencion' => 'Intervención', 'orientacion_familiar' => 'Orientación familiar', 'etapas_vida' => 'Etapas de la vida', 'otro' => 'Otra'];
        // Gerencia ve el detalle operativo sin nombres de pacientes (minimización de datos de salud).
        $paciente = $a['uso'] === 'arriendo' ? 'Arriendo de box' : ($u->rol === Rol::Gerencia ? 'Paciente #' . $a['paciente_id'] : (string) $a['paciente']);
        return ['id' => (int) $a['id'], 'fecha' => $a['fecha'], 'hora' => $a['hora'], 'box' => $a['box'], 'profesional' => $a['profesional'],
            'especialidad' => $a['especialidad'], 'paciente' => $paciente, 'tipo' => $tipos[$a['tipo_atencion'] ?? ''] ?? '—',
            'estado' => $estados[$a['estado']] ?? $a['estado'], 'estado_clave' => $a['estado'], 'precio_clp' => (int) $a['precio_clp'],
            'cobrada' => $a['cobrada'] ? 'Sí' : 'No'];
    }

    private function filaDocumento(array $d): array
    {
        $pago = ['pagado' => 'Pagado', 'parcial' => 'Abono parcial', 'pendiente' => 'Por cobrar'][$d['pago_estado']] ?? $d['pago_estado'];
        if ($d['estado'] === 'anulada') {
            $pago = 'Anulado';
        } elseif ((int) $d['vencido']) {
            $pago = 'Vencido';
        }
        return ['id' => (int) $d['id'], 'fecha' => $d['fecha'], 'documento' => ($d['tipo'] === 'factura' ? 'Factura exenta' : 'Boleta exenta') . ' (demo)',
            'folio' => (int) $d['folio'], 'receptor_nombre' => $d['receptor_nombre'], 'empresa' => $d['empresa'], 'total_clp' => (int) $d['total_clp'],
            'nc_clp' => (int) $d['nc_clp'], 'pagado_clp' => (int) $d['pagado_clp'], 'saldo_clp' => (int) $d['saldo_clp'], 'pago' => $pago,
            'pago_estado' => $d['pago_estado'], 'vence_en' => $d['vence_en'], 'dias_pago' => $d['dias_pago'] !== null ? (int) $d['dias_pago'] : null];
    }

    /** El reporte de documentos es parametrizable por rango de fechas: el rango es obligatorio. */
    private function filtrosDocumentos(array $query): array
    {
        $f = $this->documentos->filtros($query);
        $faltan = [];
        foreach (['desde', 'hasta'] as $k) {
            if (empty($f[$k])) {
                $faltan[] = ['field' => $k, 'message' => 'Este reporte requiere un rango de fechas.'];
            }
        }
        if ($faltan) {
            throw ApiException::validation($faltan);
        }
        return $f;
    }

    private function sumaPrecio(array $f): int
    {
        return $this->atencionRepo->sumaPrecio($f);
    }

    private function tope(int $n): void
    {
        if ($n > self::TOPE_EXPORTACION) {
            throw new ApiException(422, 'DEMASIADAS_FILAS', 'El reporte tiene ' . number_format($n, 0, ',', '.') . ' filas; el máximo por exportación es '
                . number_format(self::TOPE_EXPORTACION, 0, ',', '.') . '. Acota el rango de fechas u otros filtros.');
        }
    }

    private function filtrosLegibles(array $f): array
    {
        $nombres = ['desde' => 'Desde', 'hasta' => 'Hasta', 'estado' => 'Estado', 'profesional_id' => 'Profesional (id)', 'box' => 'Box',
            'especialidad' => 'Especialidad', 'uso' => 'Uso', 'q' => 'Búsqueda', 'tipo' => 'Tipo', 'pago_estado' => 'Estado de pago', 'vencidos' => 'Solo vencidos'];
        $out = [];
        foreach ($f as $k => $v) {
            if ($v === null || $v === '' || $k === 'sort' || !isset($nombres[$k])) {
                continue;
            }
            $out[$nombres[$k]] = in_array($k, ['desde', 'hasta'], true) ? date('d-m-Y', strtotime((string) $v)) : (is_bool($v) ? ($v ? 'sí' : 'no') : (string) $v);
        }
        return $out ?: ['Filtros' => 'ninguno (todos los registros)'];
    }
}

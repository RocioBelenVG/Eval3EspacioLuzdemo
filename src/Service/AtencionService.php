<?php
declare(strict_types=1);

namespace EspacioLuz\Service;

use EspacioLuz\Domain\EstadoReserva;
use EspacioLuz\Domain\Rol;
use EspacioLuz\Dte\EmisorDte;
use EspacioLuz\Exception\ApiException;
use EspacioLuz\Infrastructure\Database;
use EspacioLuz\Repository\AtencionRepository;
use EspacioLuz\Repository\CatalogoRepository;
use EspacioLuz\Repository\DocumentoRepository;
use EspacioLuz\Repository\PacienteRepository;
use EspacioLuz\Security\AuthUser;
use EspacioLuz\Validation\Validator;

/**
 * Casos de uso de la agenda: listar, agendar, reprogramar, cambiar de estado y cobrar
 * una atención con boleta (demo). Toda regla de negocio vive aquí, no en el controlador.
 */
final class AtencionService
{
    public const TIPOS_ATENCION = ['evaluacion', 'intervencion', 'orientacion_familiar', 'etapas_vida', 'otro'];
    public const MEDIOS_PAGO = ['efectivo', 'tarjeta', 'transferencia', 'otro'];

    public function __construct(
        private readonly Database $db,
        private readonly AtencionRepository $atenciones,
        private readonly PacienteRepository $pacientes,
        private readonly CatalogoRepository $catalogo,
        private readonly DocumentoRepository $documentos,
        private readonly EmisorDte $emisor,
        private readonly ?\Closure $hoy = null,
    ) {
    }

    private function hoy(): string
    {
        return $this->hoy ? ($this->hoy)() : date('Y-m-d');
    }

    /** Filtros de la lista y del reporte operativo (validados, 422 si algo no cuadra). */
    public function filtros(array $query): array
    {
        $f = Validator::check($query, [
            'q' => ['string', 'max:80'],
            'estado' => ['in:' . implode(',', EstadoReserva::valores())],
            'desde' => ['date'],
            'hasta' => ['date'],
            'profesional_id' => ['int', 'min:1'],
            'paciente_id' => ['int', 'min:1'],
            'box' => ['string', 'max:60', 'regex:/^[a-z0-9-]+$/'],
            'especialidad' => ['string', 'max:60', 'regex:/^[a-z0-9-]+$/'],
            'uso' => ['in:sesion,arriendo'],
            'sort' => ['in:' . implode(',', array_keys(AtencionRepository::ORDENES))],
        ]);
        if (!empty($f['desde']) && !empty($f['hasta']) && $f['desde'] > $f['hasta']) {
            throw ApiException::field('hasta', 'La fecha final debe ser igual o posterior a la inicial.');
        }
        return $f;
    }

    public function listar(AuthUser $u, array $query): array
    {
        $f = $this->filtros($query);
        [$page, $perPage] = Validator::paginacion($query);
        // OWASP API1 (BOLA): el área clínica solo ve sus propias atenciones.
        if ($u->rol === Rol::Clinico) {
            $f['profesional_id'] = $u->profesionalId ?? -1;
        }
        return $this->atenciones->listar($f, $f['sort'] ?? '-fecha', $page, $perPage);
    }

    public function ver(AuthUser $u, int $id): array
    {
        $a = $this->atenciones->porId($id) ?? throw ApiException::notFound('La atención no existe.');
        if ($u->rol === Rol::Clinico && (int) $a['profesional_id'] !== $u->profesionalId) {
            throw ApiException::notFound('La atención no existe.');  // no revela que existe
        }
        $a['transiciones'] = array_map(fn (EstadoReserva $e) => $e->value, EstadoReserva::from($a['estado'])->siguientes());
        return $a;
    }

    /** CU-01 · Agendar una atención (sesión con paciente o arriendo de box). */
    public function crear(AuthUser $u, array $body): array
    {
        $d = Validator::check($body, [
            'box' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9-]+$/'],
            'profesional_id' => ['required', 'int', 'min:1'],
            'paciente_id' => ['nullable', 'int', 'min:1'],
            'fecha' => ['required', 'date'],
            'hora' => ['required', 'time'],
            'duracion_min' => ['nullable', 'int', 'min:15', 'max:480'],
            'tipo_atencion' => ['nullable', 'in:' . implode(',', self::TIPOS_ATENCION)],
            'precio_clp' => ['nullable', 'int', 'min:0', 'max:5000000'],
            'estado' => ['nullable', 'in:pendiente,confirmada'],
            'notas' => ['nullable', 'string', 'max:500'],
        ]);
        $this->validarAgenda($d['fecha'], $d['hora']);

        $box = $this->catalogo->box($d['box']);
        if (!$box || !(int) $box['activo']) {
            throw ApiException::field('box', 'El box no existe o no está disponible para reservar.');
        }
        $prof = $this->catalogo->profesional($d['profesional_id']);
        if (!$prof || !(int) $prof['activo']) {
            throw ApiException::field('profesional_id', 'El profesional no existe o está inactivo.');
        }
        $pac = null;
        if (!empty($d['paciente_id'])) {
            $pac = $this->pacientes->basico($d['paciente_id']);
            if (!$pac || !(int) $pac['activo']) {
                throw ApiException::field('paciente_id', 'El paciente no existe o está inactivo.');
            }
        }
        if ($pac === null && !empty($d['tipo_atencion'])) {
            throw ApiException::field('tipo_atencion', 'El tipo de atención solo aplica a sesiones con paciente.');
        }
        $tarifas = json_decode((string) $box['tarifas'], true) ?: [];
        $precio = $d['precio_clp'] ?? ($pac === null ? (int) ($tarifas[0]['precio'] ?? 0) : null);

        $id = $this->db->transactional(function () use ($d, $pac, $prof, $precio) {
            $this->verificarDisponibilidad($d['box'], (int) $d['profesional_id'], $d['fecha'], $d['hora'], 0);
            try {
                return $this->atenciones->crear([
                    'box' => $d['box'], 'profesional_id' => $d['profesional_id'], 'paciente_id' => $pac['id'] ?? null,
                    'modalidad_label' => $pac ? 'Sesión de ' . mb_strtolower((string) $prof['especialidad']) : 'Arriendo de box',
                    'tipo_atencion' => $pac ? ($d['tipo_atencion'] ?? 'intervencion') : null, 'precio_clp' => $precio,
                    'fecha' => $d['fecha'], 'hora' => $d['hora'], 'duracion_min' => $d['duracion_min'] ?? 60,
                    'nombre' => $pac['nombre'] ?? $prof['nombre'], 'email' => (string) ($pac['email'] ?? ''), 'telefono' => (string) ($pac['telefono'] ?? ''),
                    'estado' => $d['estado'] ?? 'pendiente', 'notas' => $d['notas'] ?? null,
                ]);
            } catch (\PDOException $e) {
                throw $this->errorBd($e);
            }
        });
        return $this->ver($u, $id);
    }

    /** CU-02 · Cambiar el estado de una atención respetando el flujo. */
    public function cambiarEstado(AuthUser $u, int $id, array $body): array
    {
        $d = Validator::check($body, [
            'estado' => ['required', 'in:' . implode(',', EstadoReserva::valores())],
            'motivo' => ['nullable', 'string', 'max:300'],
        ]);
        $destino = EstadoReserva::from($d['estado']);

        $this->db->transactional(function () use ($u, $id, $destino, $d) {
            $r = $this->atenciones->bloquear($id) ?? throw ApiException::notFound('La atención no existe.');
            if ($u->rol === Rol::Clinico) {
                if ((int) $r['profesional_id'] !== $u->profesionalId) {
                    throw ApiException::notFound('La atención no existe.');
                }
                if (!$destino->esCierre()) {
                    throw ApiException::forbidden('El área clínica solo puede registrar si la atención se realizó o si el paciente no asistió.');
                }
            }
            $actual = EstadoReserva::from((string) $r['estado']);
            if ($actual === $destino) {
                throw ApiException::conflict("La atención ya está en estado «{$destino->etiqueta()}».", 'SIN_CAMBIO');
            }
            if (!$actual->puedePasarA($destino)) {
                $permitidos = array_map(fn (EstadoReserva $e) => $e->etiqueta(), $actual->siguientes());
                throw ApiException::conflict("No se puede pasar de «{$actual->etiqueta()}» a «{$destino->etiqueta()}». "
                    . ($permitidos ? 'Desde aquí solo se permite: ' . implode(', ', $permitidos) . '.' : 'Es un estado de cierre: ya no admite cambios.'), 'TRANSICION_INVALIDA');
            }
            if ($destino->exigeFechaCumplida() && $r['fecha'] > $this->hoy()) {
                throw ApiException::field('estado', 'Una atención futura no puede marcarse como realizada ni como inasistencia.');
            }
            if ($destino === EstadoReserva::Cancelada && ($r['boleta_id'] || $r['factura_id'])) {
                throw ApiException::conflict('Esta atención ya está cobrada. Cancélala desde el panel de documentos, donde se decide si se emite una nota de crédito o se mantiene el cobro.', 'COBRO_ASOCIADO');
            }
            $reactiva = $actual === EstadoReserva::Cancelada;
            if ($reactiva) {
                $this->validarAgenda((string) $r['fecha'], substr((string) $r['hora'], 0, 5), false);
                $this->verificarDisponibilidad((string) $r['box_slug'], (int) $r['profesional_id'], (string) $r['fecha'], substr((string) $r['hora'], 0, 5), $id);
            }
            try {
                $this->atenciones->cambiarEstado($id, $destino->value, $reactiva);
            } catch (\PDOException $e) {
                throw $this->errorBd($e);
            }
            if ($destino === EstadoReserva::Cancelada || $reactiva) {
                $this->atenciones->registrarMovimiento($id, $reactiva ? 'reactivacion' : 'cancelacion', $r['profesional_id'] ? (int) $r['profesional_id'] : null,
                    $u->id, $u->nombre, trim(($reactiva ? 'Reactivada como ' . $destino->etiqueta() : 'Cancelación vía API') . ($d['motivo'] ? ' · ' . $d['motivo'] : '')));
            }
        });
        return $this->ver($u, $id);
    }

    /** CU-01b · Reprogramar fecha, hora o box de una atención pendiente o confirmada. */
    public function reprogramar(AuthUser $u, int $id, array $body): array
    {
        $d = Validator::check($body, [
            'fecha' => ['required', 'date'],
            'hora' => ['required', 'time'],
            'box' => ['nullable', 'string', 'max:60', 'regex:/^[a-z0-9-]+$/'],
        ]);
        $this->validarAgenda($d['fecha'], $d['hora']);
        $this->db->transactional(function () use ($u, $id, $d) {
            $r = $this->atenciones->bloquear($id) ?? throw ApiException::notFound('La atención no existe.');
            $estado = EstadoReserva::from((string) $r['estado']);
            if (!in_array($estado, [EstadoReserva::Pendiente, EstadoReserva::Confirmada], true)) {
                throw ApiException::conflict("Solo se reprograman atenciones pendientes o confirmadas (esta está «{$estado->etiqueta()}»).", 'TRANSICION_INVALIDA');
            }
            $box = $d['box'] ?? (string) $r['box_slug'];
            if ($box !== $r['box_slug']) {
                $b = $this->catalogo->box($box);
                if (!$b || !(int) $b['activo']) {
                    throw ApiException::field('box', 'El box no existe o no está disponible para reservar.');
                }
            }
            $this->verificarDisponibilidad($box, (int) $r['profesional_id'], $d['fecha'], $d['hora'], $id);
            try {
                $this->atenciones->reprogramar($id, $d['fecha'], $d['hora'], $box);
            } catch (\PDOException $e) {
                throw $this->errorBd($e);
            }
            $this->atenciones->registrarMovimiento($id, 'reagenda', $r['profesional_id'] ? (int) $r['profesional_id'] : null, $u->id, $u->nombre,
                "De {$r['fecha']} " . substr((string) $r['hora'], 0, 5) . " a {$d['fecha']} {$d['hora']}" . ($box !== $r['box_slug'] ? " (box $box)" : ''));
        });
        return $this->ver($u, $id);
    }

    /** CU-05 · Cobrar una sesión realizada con una boleta exenta de demostración. */
    public function emitirBoleta(AuthUser $u, int $id, array $body): array
    {
        $d = Validator::check($body, [
            'medio_pago' => ['required', 'in:' . implode(',', self::MEDIOS_PAGO)],
            'pagado' => ['nullable', 'bool'],
        ]);
        $empresa = $this->catalogo->empresaBoletas() ?? throw ApiException::conflict('No hay una empresa activa configurada para emitir boletas.', 'SIN_EMISOR');

        $docId = $this->db->transactional(function () use ($u, $id, $d, $empresa) {
            $r = $this->atenciones->bloquear($id) ?? throw ApiException::notFound('La atención no existe.');
            if ($r['paciente_id'] === null) {
                throw ApiException::conflict('Los arriendos de box se cobran con la factura mensual del cliente, no con boleta.', 'NO_ES_SESION');
            }
            if ($r['estado'] !== EstadoReserva::Realizada->value) {
                throw ApiException::conflict('Solo se cobran atenciones realizadas.', 'TRANSICION_INVALIDA');
            }
            if ($r['boleta_id'] !== null) {
                throw ApiException::conflict('Esta atención ya tiene boleta.', 'YA_COBRADA');
            }
            $precio = (int) ($r['precio_clp'] ?? 0);
            if ($precio <= 0) {
                throw ApiException::conflict('La atención no tiene precio: asígnalo antes de emitir la boleta.', 'SIN_PRECIO');
            }
            $pac = $this->pacientes->basico((int) $r['paciente_id']);
            $prof = $this->catalogo->profesional((int) $r['profesional_id']);
            $menor = $pac['fecha_nacimiento'] && (int) date_diff(date_create((string) $pac['fecha_nacimiento']), date_create($this->hoy()))->y < 18;
            $tutor = $menor && !empty($pac['apoderado']);
            $receptorNombre = $tutor ? (string) $pac['apoderado'] : (string) $pac['nombre'];
            $receptorRut = $tutor ? (string) ($pac['apoderado_rut'] ?? '') : (string) ($pac['rut'] ?? '');

            $res = $this->emisor->emitir((int) $empresa['id'], 41,
                [['descripcion' => (string) $r['modalidad_label'], 'cantidad' => 1, 'precio_unitario' => $precio, 'unidad' => 'UN', 'exento' => 1]],
                ['rut' => $receptorRut, 'razon_social' => $receptorNombre, 'contacto' => (string) ($pac['email'] ?? '')],
                (int) $pac['id'], 'Atención del ' . date('d-m-Y', strtotime((string) $r['fecha'])), $u->id, $u->nombre);
            if (!($res['ok'] ?? false)) {
                throw new ApiException(502, 'DTE_ERROR', 'No se pudo emitir la boleta de demostración: ' . ($res['error'] ?? 'error desconocido'));
            }
            $docId = $this->documentos->crearBoleta([
                'folio' => (int) $res['folio'], 'empresa_id' => (int) $empresa['id'], 'usuario_id' => $u->id,
                'emisor_nombre' => $empresa['nombre_fantasia'] ?: $empresa['razon_social'], 'receptor_nombre' => $receptorNombre,
                'receptor_rut' => $receptorRut !== '' ? $receptorRut : null, 'receptor_email' => $pac['email'] ?: null,
                'paciente_id' => (int) $pac['id'], 'receptor_tipo' => $tutor ? 'tutor' : 'paciente', 'reserva_id' => $id,
                'profesional_id' => $prof ? (int) $prof['id'] : null, 'profesional_nombre' => $prof['nombre'] ?? null,
                'medio_pago' => $d['medio_pago'], 'neto' => (int) ($res['neto'] ?? 0), 'iva' => (int) ($res['iva'] ?? 0),
                'exento' => (int) ($res['exento'] ?? $precio), 'total' => (int) ($res['total'] ?? $precio),
                'glosa' => 'Atención del ' . date('d-m-Y', strtotime((string) $r['fecha'])), 'trackid' => $res['trackid'] ?? null,
                'descripcion' => (string) $r['modalidad_label'], 'documento_dte_id' => $res['documento_id'] ?? null,
            ]);
            $this->atenciones->vincularBoleta($id, $docId, (int) ($res['total'] ?? $precio));
            if ($d['pagado'] ?? true) {
                $this->documentos->agregarPago($docId, $this->hoy(), (int) ($res['total'] ?? $precio), $d['medio_pago'], 'Pago al emitir', $u->nombre);
                $this->documentos->recalcularPago($docId);
            }
            return $docId;
        });
        return $this->documentos->porId($docId) ?? [];
    }

    // ------------------------------------------------------------------ reglas de agenda

    /** Reglas de calendario: no en el pasado, no domingos ni feriados, dentro del horario del centro, en bloques de 30 min. */
    private function validarAgenda(string $fecha, string $hora, bool $exigirFuturo = true): void
    {
        if ($exigirFuturo && $fecha < $this->hoy()) {
            throw ApiException::field('fecha', 'No se pueden agendar atenciones en fechas pasadas.');
        }
        $dia = (int) date('N', strtotime($fecha));
        if ($dia === 7) {
            throw ApiException::field('fecha', 'El centro no atiende los domingos.');
        }
        if ($nombre = $this->catalogo->feriadoCerrado($fecha)) {
            throw ApiException::field('fecha', "El centro está cerrado ese día (feriado: $nombre).");
        }
        if (!preg_match('/:(00|30)$/', $hora)) {
            throw ApiException::field('hora', 'Las atenciones se agendan en bloques de 30 minutos (por ejemplo 10:00 o 10:30).');
        }
        $h = $this->catalogo->horarioDia($dia);
        if ($h === null || !$h['abierto'] || $hora < $h['inicio'] || $hora >= $h['fin']) {
            throw ApiException::field('hora', $h && $h['abierto'] ? "Fuera del horario del centro ({$h['inicio']} a {$h['fin']})." : 'El centro no abre ese día.');
        }
    }

    private function verificarDisponibilidad(string $box, int $profesionalId, string $fecha, string $hora, int $excepto): void
    {
        if ($this->atenciones->ocupacionBox($box, $fecha, $hora, $excepto) >= $this->catalogo->cupoBox()) {
            throw ApiException::conflict('El box ya no tiene cupo en ese horario. Elige otra hora u otro box.', 'BOX_SIN_CUPO');
        }
        if ($this->atenciones->choqueProfesional($profesionalId, $fecha, $hora, $excepto)) {
            throw ApiException::conflict('El profesional ya tiene otra atención en ese horario.', 'PROFESIONAL_OCUPADO');
        }
    }

    /** Traduce los errores que levanta la base de datos (disparadores y restricciones) al formato estándar. */
    private function errorBd(\PDOException $e): ApiException
    {
        $m = $e->getMessage();
        return match (true) {
            str_contains($m, 'BOX_SIN_CUPO') => ApiException::conflict('El box ya no tiene cupo en ese horario.', 'BOX_SIN_CUPO'),
            str_contains($m, 'DIA_CERRADO_DOMINGO') => ApiException::field('fecha', 'El centro no atiende los domingos.'),
            str_contains($m, 'DIA_CERRADO_FERIADO') => ApiException::field('fecha', 'El centro está cerrado ese día (feriado).'),
            str_contains($m, 'TRANSICION_INVALIDA') => ApiException::conflict('Cambio de estado no permitido por el flujo de la atención.', 'TRANSICION_INVALIDA'),
            str_contains($m, 'ESTADO_FECHA_FUTURA') => ApiException::field('estado', 'Una atención futura no puede marcarse como realizada.'),
            str_contains($m, 'uq_slot_prof') => ApiException::conflict('El profesional ya tiene otra atención en ese horario.', 'PROFESIONAL_OCUPADO'),
            str_contains($m, 'chk_') => new ApiException(422, 'VALIDATION_ERROR', 'Un dato no cumple las reglas de la base de datos.'),
            default => throw $e,
        };
    }
}

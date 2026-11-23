<?php
declare(strict_types=1);

namespace EspacioLuz\Service;

use EspacioLuz\Domain\Rol;
use EspacioLuz\Exception\ApiException;
use EspacioLuz\Repository\CatalogoRepository;
use EspacioLuz\Repository\PacienteRepository;
use EspacioLuz\Security\AuthUser;
use EspacioLuz\Validation\Validator;

/** CU-03 · Registro y consulta de pacientes (con su consentimiento y su tutor si es menor). */
final class PacienteService
{
    public function __construct(
        private readonly PacienteRepository $pacientes,
        private readonly CatalogoRepository $catalogo,
    ) {
    }

    public function listar(AuthUser $u, array $query): array
    {
        $f = Validator::check($query, [
            'q' => ['string', 'max:80'],
            'activo' => ['bool'],
            'comuna' => ['string', 'max:120'],
            'prevision' => ['string', 'max:60'],
            'sort' => ['in:' . implode(',', array_keys(PacienteRepository::ORDENES))],
        ]);
        [$page, $perPage] = Validator::paginacion($query);
        $soloDe = $u->rol === Rol::Clinico ? ($u->profesionalId ?? -1) : null;
        $r = $this->pacientes->listar($f, $f['sort'] ?? 'nombre', $page, $perPage, $soloDe);
        $r['data'] = array_map(fn ($p) => $this->serializar($p, $u), $r['data']);
        return $r;
    }

    public function ver(AuthUser $u, int $id): array
    {
        $p = $this->pacientes->porId($id) ?? throw ApiException::notFound('El paciente no existe.');
        if ($u->rol === Rol::Clinico && !$this->pacientes->atendidoPor($id, $u->profesionalId ?? -1)) {
            throw ApiException::notFound('El paciente no existe.');
        }
        return $this->serializar($p, $u);
    }

    public function crear(AuthUser $u, array $body): array
    {
        $d = $this->validar($body, true);
        if ($this->pacientes->idPorRut($d['rut']) !== null) {
            throw ApiException::conflict('Ya existe un paciente con ese RUT.', 'RUT_DUPLICADO');
        }
        $id = $this->pacientes->crear($d);
        return $this->ver($u, $id);
    }

    public function actualizar(AuthUser $u, int $id, array $body): array
    {
        if (!$this->pacientes->existe($id)) {
            throw ApiException::notFound('El paciente no existe.');
        }
        $d = $this->validar($body, false);
        if (isset($d['rut']) && $this->pacientes->idPorRut($d['rut'], $id) !== null) {
            throw ApiException::conflict('Ya existe otro paciente con ese RUT.', 'RUT_DUPLICADO');
        }
        $this->pacientes->actualizar($id, $d);
        return $this->ver($u, $id);
    }

    private function validar(array $body, bool $alta): array
    {
        $req = $alta ? 'required' : 'nullable';
        $d = Validator::check($body, [
            'nombre' => [$req, 'string', 'min:3', 'max:200', 'regex:/^[\p{L}\' .-]+$/u'],
            'rut' => [$req, 'rut'],
            'email' => ['nullable', 'email', 'max:200'],
            'telefono' => ['nullable', 'string', 'max:40', 'regex:/^\+?[0-9 ()-]{8,20}$/'],
            'fecha_nacimiento' => [$req, 'date'],
            'sexo' => ['nullable', 'in:hombre,mujer,otro'],
            'direccion' => ['nullable', 'string', 'max:300'],
            'region' => ['nullable', 'string', 'max:80'],
            'comuna' => ['nullable', 'string', 'max:120'],
            'prevision' => ['nullable', 'string', 'max:60'],
            'apoderado' => ['nullable', 'string', 'min:3', 'max:200'],
            'apoderado_rut' => ['nullable', 'rut'],
            'apoderado_telefono' => ['nullable', 'string', 'max:40', 'regex:/^\+?[0-9 ()-]{8,20}$/'],
            'contacto_emergencia' => ['nullable', 'string', 'max:300'],
            'notas' => ['nullable', 'string', 'max:2000'],
            'consentimiento' => [$alta ? 'required' : 'nullable', 'bool'],
            'activo' => ['nullable', 'bool'],
        ]);
        $errores = [];
        if ($alta && ($d['consentimiento'] ?? false) !== true) {
            $errores[] = ['field' => 'consentimiento', 'message' => 'Se requiere el consentimiento informado para registrar al paciente (Ley 20.584).'];
        }
        if (!empty($d['fecha_nacimiento'])) {
            if ($d['fecha_nacimiento'] > date('Y-m-d')) {
                $errores[] = ['field' => 'fecha_nacimiento', 'message' => 'La fecha de nacimiento no puede estar en el futuro.'];
            } elseif ($d['fecha_nacimiento'] < '1900-01-01') {
                $errores[] = ['field' => 'fecha_nacimiento', 'message' => 'La fecha de nacimiento no es válida.'];
            } elseif ($alta && (int) date_diff(date_create($d['fecha_nacimiento']), date_create())->y < 18 && empty($d['apoderado'])) {
                $errores[] = ['field' => 'apoderado', 'message' => 'Los pacientes menores de edad deben registrar a su apoderado o tutor.'];
            }
        }
        if (!empty($d['prevision']) && !in_array($d['prevision'], $this->catalogo->previsiones(), true)) {
            $errores[] = ['field' => 'prevision', 'message' => 'La previsión no está en el catálogo.'];
        }
        if ($errores) {
            throw ApiException::validation($errores);
        }
        foreach (['rut', 'apoderado_rut'] as $k) {
            if (!empty($d[$k])) {
                $d[$k] = self::formatoRut($d[$k]);
            }
        }
        unset($d['consentimiento']);
        return $d;
    }

    /** 12345678-9 → 12.345.678-9 (formato del sistema original). */
    public static function formatoRut(string $rut): string
    {
        [$cuerpo, $dv] = explode('-', strtoupper($rut));
        return number_format((int) $cuerpo, 0, '', '.') . '-' . $dv;
    }

    /** OWASP API3: Gerencia nunca recibe datos de contacto; el resto ve solo lo necesario. */
    private function serializar(array $p, AuthUser $u): array
    {
        unset($p['notas']);
        if ($u->rol === Rol::Gerencia) {
            foreach (['rut', 'email', 'telefono', 'direccion', 'apoderado', 'apoderado_rut', 'apoderado_telefono', 'contacto_emergencia'] as $k) {
                unset($p[$k]);
            }
        }
        return $p;
    }
}

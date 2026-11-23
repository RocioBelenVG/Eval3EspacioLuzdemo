<?php
declare(strict_types=1);

namespace EspacioLuz\Repository;

/** Catálogos de lectura: profesionales, boxes, especialidades, feriados, horario y empresas. */
final class CatalogoRepository extends Repository
{
    public function profesionales(): array
    {
        return $this->all('SELECT p.id, p.nombre, p.titulo, p.especialidad_slug, e.nombre AS especialidad, p.socia, p.box_slug, p.activo
                             FROM profesionales p JOIN especialidades e ON e.slug = p.especialidad_slug
                            WHERE p.eliminado_at IS NULL ORDER BY p.orden, p.nombre');
    }

    public function profesional(int $id): ?array
    {
        return $this->one('SELECT p.id, p.nombre, p.rut, p.especialidad_slug, e.nombre AS especialidad, p.socia, p.empresa_id, p.activo
                             FROM profesionales p JOIN especialidades e ON e.slug = p.especialidad_slug
                            WHERE p.id = ? AND p.eliminado_at IS NULL', [$id]);
    }

    public function boxes(): array
    {
        return $this->all('SELECT slug, nombre, ave, ubicacion, tarifas, activo FROM boxes WHERE eliminado_at IS NULL ORDER BY orden, nombre');
    }

    public function box(string $slug): ?array
    {
        return $this->one('SELECT slug, nombre, tarifas, activo FROM boxes WHERE slug = ? AND eliminado_at IS NULL', [$slug]);
    }

    public function especialidades(): array
    {
        return $this->all('SELECT slug, nombre FROM especialidades WHERE activo = 1 ORDER BY orden, nombre');
    }

    public function comunas(): array
    {
        return array_column($this->all('SELECT DISTINCT comuna FROM pacientes WHERE comuna IS NOT NULL ORDER BY comuna'), 'comuna');
    }

    public function previsiones(): array
    {
        return array_column($this->all('SELECT nombre FROM previsiones WHERE activo = 1 ORDER BY orden, nombre'), 'nombre');
    }

    public function feriadoCerrado(string $fecha): ?string
    {
        $n = $this->scalar('SELECT nombre FROM feriados WHERE fecha = ? AND se_trabaja = 0', [$fecha]);
        return $n === false ? null : (string) $n;
    }

    /** @return array{abierto: bool, inicio: string, fin: string}|null  (dia 1 = lunes … 6 = sábado) */
    public function horarioDia(int $dia): ?array
    {
        $h = $this->one("SELECT abierto, TIME_FORMAT(hora_inicio, '%H:%i') AS inicio, TIME_FORMAT(hora_fin, '%H:%i') AS fin FROM horario_centro WHERE dia_semana = ?", [$dia]);
        return $h === null ? null : ['abierto' => (bool) $h['abierto'], 'inicio' => $h['inicio'], 'fin' => $h['fin']];
    }

    public function cupoBox(): int
    {
        $v = $this->scalar("SELECT valor FROM configuracion WHERE clave = 'box_cupo_max'");
        return $v === false ? 2 : max(1, (int) $v);
    }

    /** Fechas cerradas (domingos no se guardan) entre dos fechas. */
    public function feriadosCerrados(string $desde, string $hasta): array
    {
        return array_column($this->all('SELECT fecha FROM feriados WHERE se_trabaja = 0 AND fecha BETWEEN ? AND ?', [$desde, $hasta]), 'fecha');
    }

    /** Empresa que emite las boletas a pacientes (tipo_documento = boleta). */
    public function empresaBoletas(): ?array
    {
        return $this->one("SELECT id, razon_social, nombre_fantasia, rut FROM empresas WHERE activo = 1 AND tipo_documento = 'boleta' ORDER BY orden LIMIT 1");
    }
}

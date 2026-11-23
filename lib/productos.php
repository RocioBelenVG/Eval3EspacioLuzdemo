<?php
/**
 * Productos / servicios por empresa — capa de datos.
 * Cada producto pertenece a UNA empresa (FK) o a AMBAS (empresa_id NULL: aparece al emitir
 * boletas de cualquier empresa que emite boletas; no en facturas). Define precio neto y si es
 * afecto a IVA o exento. Al emitir un documento, los ítems se eligen de aquí
 * (la mayoría por select), quedando anclados a la empresa emisora.
 */
declare(strict_types=1);

require_once APP_ROOT . '/lib/db.php';

/**
 * Lista de productos. Filtra por empresa si $empresaId > 0 y por profesional si $profesionalId > 0
 * (sus productos + los que aún no tienen profesional asignado).
 * Cada producto pertenece a UNA socia (Fernanda o Javiera): así cada una identifica lo suyo al emitir.
 */
function productosLista(int $empresaId = 0, bool $incluirInactivos = false, int $profesionalId = 0): array
{
    try {
        $sql = "SELECT p.*, COALESCE(e.razon_social, 'Ambas empresas') AS empresa_nombre, COALESCE(e.tipo_documento, 'boleta') AS empresa_tipo, pr.nombre AS profesional_nombre
                  FROM productos p LEFT JOIN empresas e ON e.id = p.empresa_id
                  LEFT JOIN profesionales pr ON pr.id = p.profesional_id";
        $cond = [];
        $params = [];
        if ($empresaId > 0) {
            $cond[] = "(p.empresa_id = ? OR p.empresa_id IS NULL)"; // los de «Ambas empresas» también
            $params[] = $empresaId;
        }
        if ($profesionalId > 0) {
            $cond[] = "(p.profesional_id = ? OR p.profesional_id IS NULL)";
            $params[] = $profesionalId;
        }
        if (!$incluirInactivos) {
            $cond[] = "p.activo = 1";
        }
        if ($cond) {
            $sql .= " WHERE " . implode(' AND ', $cond);
        }
        $sql .= " ORDER BY (p.empresa_id IS NULL), e.orden, pr.nombre, p.orden, p.nombre";
        $st = db()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** Empresas activas que emiten boletas (destino de los productos de «Ambas empresas»). */
function productoEmpresasBoleta(): array
{
    try {
        return array_map('intval', db()->query("SELECT id FROM empresas WHERE activo = 1 AND tipo_documento <> 'factura' ORDER BY orden, id")->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Productos activos agrupados por empresa_id (para poblar selects en la emisión).
 * $conAmbas: los productos de «Ambas empresas» se suman a cada empresa que emite boletas
 * (en la factura a clientes se llama con false: esos productos son para boletas).
 */
function productosPorEmpresa(bool $conAmbas = true): array
{
    $out = [];
    $boletas = $conAmbas ? productoEmpresasBoleta() : [];
    foreach (productosLista(0, false) as $p) {
        $ambas = $p['empresa_id'] === null;
        if ($ambas && !$conAmbas) continue;
        foreach ($ambas ? $boletas : [(int) $p['empresa_id']] as $empId) $out[$empId][] = [
            'id' => (int) $p['id'],
            'nombre' => $p['nombre'],
            'precio_neto' => (int) $p['precio_neto'],
            'afecto' => (int) $p['afecto'],
            'profesional_id' => $p['profesional_id'] !== null ? (int) $p['profesional_id'] : null,
            'profesional_nombre' => (string) ($p['profesional_nombre'] ?? ''),
            'ambas' => $ambas,
        ];
    }
    return $out;
}

function getProducto(int $id): ?array
{
    try {
        $st = db()->prepare("SELECT * FROM productos WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Profesional dueña del producto (debe ser socia: Fernanda o Javiera). ['ok'=>true,'id'=>int] o error. */
function productoProfesionalValido($valor): array
{
    require_once APP_ROOT . '/lib/panel.php';
    $id = (int) $valor;
    $socias = profesionalesSocias();
    if ($id <= 0 || !isset($socias[$id])) {
        return ['ok' => false, 'error' => 'Elige de qué profesional es el producto (' . (sociasTexto() ?: 'socias') . ').'];
    }
    return ['ok' => true, 'id' => $id];
}

/** Profesional dueña de un producto (para guardarla en los ítems de cada documento), o null. */
function productoProfesionalId(int $productoId): ?int
{
    if ($productoId <= 0) return null;
    try {
        $st = db()->prepare("SELECT profesional_id FROM productos WHERE id = ?");
        $st->execute([$productoId]);
        $v = $st->fetchColumn();
        return $v !== false && $v !== null ? (int) $v : null;
    } catch (Throwable $e) {
        return null;
    }
}

/** ¿El producto debe ser exento? (la empresa solo emite documentos exentos: 34/41).
 *  Producto de «Ambas empresas» ($empresaId null/0): exento si ALGUNA empresa que emite boletas solo emite exentos. */
function productoForzarExento(?int $empresaId): bool
{
    if (!$empresaId) {
        foreach (productoEmpresasBoleta() as $id) {
            if (productoForzarExento($id)) return true;
        }
        return false;
    }
    try {
        require_once APP_ROOT . '/lib/dte_integra.php';
        $st = db()->prepare("SELECT dte_certificados FROM empresas WHERE id = ?");
        $st->execute([$empresaId]);
        $c = $st->fetchColumn();
        return $c !== false && dteEmpresaSoloExenta(['dte_certificados' => $c]);
    } catch (Throwable $e) {
        return false;
    }
}

/** Empresa elegida en el formulario: id de una empresa existente, o 'ambas' (null). */
function productoEmpresaValor($valor): array
{
    if ((string) $valor === 'ambas') {
        return ['ok' => true, 'id' => null];
    }
    $id = (int) $valor;
    try {
        $st = db()->prepare("SELECT id FROM empresas WHERE id = ?");
        $st->execute([$id]);
        if ($id > 0 && $st->fetchColumn()) return ['ok' => true, 'id' => $id];
    } catch (Throwable $e) {
    }
    return ['ok' => false, 'error' => 'Debes elegir la empresa (o «Ambas empresas»).'];
}

function crearProducto(array $d): array
{
    $emp = productoEmpresaValor($d['empresa_id'] ?? '');
    if (!$emp['ok']) {
        return $emp;
    }
    $empresaId = $emp['id'];
    $nombre = trim((string) ($d['nombre'] ?? ''));
    $prof = productoProfesionalValido($d['profesional_id'] ?? 0);
    if (!$prof['ok']) {
        return $prof;
    }
    if ($nombre === '') {
        return ['ok' => false, 'error' => 'El nombre del producto es obligatorio.'];
    }
    $precio = max(0, (int) preg_replace('/\D/', '', (string) ($d['precio_neto'] ?? 0)));
    try {
        $st = db()->prepare("INSERT INTO productos (empresa_id, profesional_id, nombre, descripcion, precio_neto, afecto, activo, orden) VALUES (?,?,?,?,?,?,?,?)");
        $st->execute([
            $empresaId,
            $prof['id'],
            mb_substr($nombre, 0, 200, 'UTF-8'),
            trim((string) ($d['descripcion'] ?? '')) ?: null,
            $precio,
            empty($d['afecto']) || productoForzarExento($empresaId) ? 0 : 1,
            empty($d['activo']) && isset($d['activo']) ? 0 : 1,
            (int) ($d['orden'] ?? 0),
        ]);
        return ['ok' => true, 'id' => (int) db()->lastInsertId()];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'No se pudo crear el producto.'];
    }
}

function actualizarProducto(int $id, array $d): array
{
    $nombre = trim((string) ($d['nombre'] ?? ''));
    if ($nombre === '') {
        return ['ok' => false, 'error' => 'El nombre del producto es obligatorio.'];
    }
    $prof = productoProfesionalValido($d['profesional_id'] ?? 0);
    if (!$prof['ok']) {
        return $prof;
    }
    $precio = max(0, (int) preg_replace('/\D/', '', (string) ($d['precio_neto'] ?? 0)));
    try {
        // La empresa se puede cambiar (o pasar a «Ambas empresas»); los documentos ya emitidos no cambian.
        $emp = productoEmpresaValor($d['empresa_id'] ?? '');
        if (!$emp['ok']) {
            return $emp;
        }
        $forzarExento = productoForzarExento($emp['id']);
        $st = db()->prepare("UPDATE productos SET empresa_id=?, profesional_id=?, nombre=?, descripcion=?, precio_neto=?, afecto=?, activo=?, orden=? WHERE id=?");
        $st->execute([
            $emp['id'],
            $prof['id'],
            mb_substr($nombre, 0, 200, 'UTF-8'),
            trim((string) ($d['descripcion'] ?? '')) ?: null,
            $precio,
            empty($d['afecto']) || $forzarExento ? 0 : 1,
            empty($d['activo']) ? 0 : 1,
            (int) ($d['orden'] ?? 0),
            $id,
        ]);
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'No se pudo actualizar el producto.'];
    }
}

function eliminarProducto(int $id): bool
{
    try {
        $st = db()->prepare("DELETE FROM productos WHERE id = ?");
        return $st->execute([$id]);
    } catch (Throwable $e) {
        return false;
    }
}

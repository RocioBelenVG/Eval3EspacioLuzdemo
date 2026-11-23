<?php
declare(strict_types=1);

/**
 * PDF de documentos de EspacioLuz (boletas y facturas) con la identidad visual
 * del centro — mismo estilo del documento interno (partials/panel/boleta-doc.php).
 *
 * Un único renderizador (dte_pdf_render) sirve para dos orígenes:
 *   - dte_pdf_bytes()    : DTE real (tabla dte_documentos) con Timbre PDF417.
 *   - (lib/boleta_pdf.php -> boleta_pdf_bytes) : documento interno MVP, sin timbre,
 *     con la nota de "pendiente de timbrado".
 *
 * Tres formatos: 'carta' (hoja completa), '80mm' y '58mm' (ticket térmico).
 * Boleta = salvia, Factura = terracota, TOTAL en caja café.
 */

require_once __DIR__ . '/folios.php';   // dte_nombre()
require_once __DIR__ . '/dte_doc.php';  // timbre PDF417 / resolución
require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

if (!function_exists('dte_pdf_e'))     { function dte_pdf_e($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('dte_pdf_money')) { function dte_pdf_money($v): string { return '$' . number_format((float)$v, 0, ',', '.'); } }
if (!function_exists('dte_pdf_medio')) {
    function dte_pdf_medio(?string $m): string {
        return ['efectivo' => 'Efectivo', 'tarjeta' => 'Tarjeta', 'transferencia' => 'Transferencia', 'otro' => 'Otro'][(string)$m] ?? '';
    }
}
if (!function_exists('dte_pdf_logo_uri')) {
    /**
     * Logo del centro (assets/images/logo/logo.png) como data URI, reducido y
     * cacheado para no inflar el PDF. '' si no existe o no se puede leer.
     */
    function dte_pdf_logo_uri(): string {
        $base = defined('APP_ROOT') ? APP_ROOT : (__DIR__ . '/../..');
        $src = $base . '/assets/images/logo/logo.png';
        if (!is_file($src)) return '';
        $cacheDir = getenv('ESPACIOLUZ_DTE_PRIV') ?: '/home/u195628024/espacioluz_dte_priv';
        $cache = $cacheDir . '/logo_pdf.png';
        if (!is_file($cache) || @filemtime($cache) < @filemtime($src)) {
            $made = false;
            if (extension_loaded('imagick')) {
                try { $im = new Imagick($src); $im->thumbnailImage(240, 0); $im->setImageFormat('png'); $im->writeImage($cache); $im->clear(); $made = true; } catch (Throwable $e) {}
            }
            if (!$made && function_exists('imagecreatefrompng')) {
                $s = @imagecreatefrompng($src);
                if ($s) {
                    $w = imagesx($s); $h = imagesy($s); $nw = 240; $nh = max(1, (int)round($h * ($nw / $w)));
                    $d = imagecreatetruecolor($nw, $nh);
                    imagealphablending($d, false); imagesavealpha($d, true);
                    imagecopyresampled($d, $s, 0, 0, 0, 0, $nw, $nh, $w, $h);
                    @imagepng($d, $cache); imagedestroy($d); imagedestroy($s); $made = true;
                }
            }
            if (!$made) $cache = $src; // sin herramientas de imagen: usa el original
        }
        $data = @file_get_contents($cache);
        return $data === false ? '' : ('data:image/png;base64,' . base64_encode($data));
    }
}

/**
 * Renderiza el PDF a partir de un arreglo normalizado $v (sin acceso a BD).
 * Claves de $v: tipo, folio, fecha, emisor{nombre,fantasia,rut,giro,acteco,dir,loc,email},
 *   receptor{es_cf,nombre,rut,giro,dir,email}, medio, emitida_por, trackid,
 *   items[{descripcion,cantidad,precio,total,exento}], neto,iva,exento,total,
 *   observaciones, timbre_uri, res_linea, mvp_note (HTML de confianza o '').
 * @return array{filename:string, bytes:string}
 */
function dte_pdf_render(array $v, string $formato = 'carta'): array
{
    $formato = in_array($formato, ['carta', '80mm', '58mm'], true) ? $formato : 'carta';

    $tipo = (int)($v['tipo'] ?? 39);
    // Facturas (33/34) y notas de crédito/débito (61/56) llevan receptor completo (giro, dirección).
    $esFactura = in_array($tipo, [33, 34, 56, 61], true);
    $esExenta  = in_array($tipo, [34, 41], true);
    $nombre = dte_nombre($tipo);

    $cafe = '#5C4033'; $crema = '#F5F1E8'; $linea = '#E4DAC9'; $gris = '#8a7c70';
    $acc  = $esFactura ? '#B85C3E' : '#6D8072';
    $tituloDoc = [33 => 'Factura electrónica', 34 => 'Factura no afecta o exenta electrónica', 39 => 'Boleta electrónica', 41 => 'Boleta exenta electrónica',
                  56 => 'Nota de débito electrónica', 61 => 'Nota de crédito electrónica'][$tipo] ?? ($esFactura ? 'Factura electrónica' : 'Boleta electrónica');
    // Referencias (obligatorias en NC/ND): [{texto}] ya armadas por dte_pdf_bytes.
    $refs = is_array($v['referencias'] ?? null) ? $v['referencias'] : [];

    $em = $v['emisor'] ?? [];
    $re = $v['receptor'] ?? [];
    $pr = $v['profesional'] ?? [];          // {nombre, rut} — profesional que atendió (opcional)
    $det = $v['items'] ?? [];
    $folio = (string)($v['folio'] ?? '');
    $fecha = (string)($v['fecha'] ?? '');
    $loc = (string)($em['loc'] ?? '');
    $esCF = !empty($re['es_cf']);
    $medio = (string)($v['medio'] ?? '');
    $timbreUri = (string)($v['timbre_uri'] ?? '');
    $resLinea = (string)($v['res_linea'] ?? '');
    $observaciones = (string)($v['observaciones'] ?? '');
    $mvpNote = (string)($v['mvp_note'] ?? '');
    $emisorNombre = (string)($em['nombre'] ?? '');
    $logoUri = dte_pdf_logo_uri();

    // -------------------- TICKET 80 / 58 mm --------------------
    if ($formato === '80mm' || $formato === '58mm') {
        $w  = $formato === '58mm' ? 164.0 : 226.0;
        $fs = $formato === '58mm' ? 7.4 : 8.4;
        $timbreW = $formato === '58mm' ? 118 : 150;

        ob_start(); ?>
<!doctype html><html lang="es"><head><meta charset="utf-8">
<style>
  @page { margin: 6pt 6pt 8pt 6pt; }
  * { font-family: 'DejaVu Sans', sans-serif; }
  body { color: <?= $cafe ?>; font-size: <?= $fs ?>pt; margin: 0; }
  .ctr { text-align: center; } .r { text-align: right; } .l { text-align: left; }
  .rs { font-family:'DejaVu Serif', serif; font-weight: bold; font-size: <?= $fs + 3 ?>pt; }
  .muted { color: <?= $gris ?>; }
  .docbox { border: 1.3pt solid <?= $acc ?>; border-radius: 6px; padding: 5px 4px; margin: 7px 0; }
  .docbox .tt { font-weight: bold; font-size: <?= $fs + 1.5 ?>pt; color: <?= $acc ?>; text-transform: uppercase; letter-spacing: .5px; }
  .docbox .fo { font-weight: bold; font-size: <?= $fs + 2 ?>pt; }
  .dash { border-top: 1px dashed <?= $linea ?>; margin: 6px 0; }
  table { width: 100%; border-collapse: collapse; }
  .it td { padding: 2px 0; vertical-align: top; }
  .tot td { padding: 1.5px 0; }
  .tot .g td { font-weight: bold; font-size: <?= $fs + 2 ?>pt; border-top: 1px solid <?= $linea ?>; padding-top: 3px; }
  .sm { font-size: <?= $fs - 1 ?>pt; }
  .timbre img { width: <?= $timbreW ?>px; }
  .mvp { border: 1px dashed <?= $acc ?>; border-radius: 5px; padding: 4px 6px; margin-top: 6px; font-size: <?= $fs - 1 ?>pt; }
</style></head><body>
  <div class="ctr">
    <?php if ($logoUri !== ''): ?><img src="<?= $logoUri ?>" style="max-height:42px;max-width:<?= (int)($timbreW) ?>px;"><br><?php endif; ?>
    <div class="rs"><?= dte_pdf_e($emisorNombre) ?></div>
    <?php if (!empty($em['rut'])): ?><div class="muted sm">RUT: <?= dte_pdf_e($em['rut']) ?></div><?php endif; ?>
    <?php if (!empty($em['giro'])): ?><div class="muted sm"><?= dte_pdf_e($em['giro']) ?></div><?php endif; ?>
    <?php if (!empty($em['dir'])): ?><div class="muted sm"><?= dte_pdf_e($em['dir']) ?><?= $loc !== '' ? ', ' . dte_pdf_e($loc) : '' ?></div><?php endif; ?>
  </div>

  <div class="docbox ctr">
    <div class="tt"><?= dte_pdf_e($tituloDoc) ?></div>
    <div class="fo">N° <?= dte_pdf_e($folio) ?></div>
    <?php if ($fecha !== ''): ?><div class="muted sm"><?= dte_pdf_e($fecha) ?></div><?php endif; ?>
  </div>

  <?php if (!$esCF): ?>
    <div class="sm"><span class="muted">Receptor:</span> <b><?= dte_pdf_e($re['nombre'] ?? '—') ?></b></div>
    <?php if (!empty($re['rut'])): ?><div class="sm"><span class="muted">RUT:</span> <?= dte_pdf_e($re['rut']) ?></div><?php endif; ?>
    <?php if (!empty($re['dir'])): ?><div class="sm"><span class="muted">Dir:</span> <?= dte_pdf_e($re['dir']) ?></div><?php endif; ?>
    <?php if (!empty($re['cod_prestacion'])): ?><div class="sm"><span class="muted">Cód. prest.:</span> <?= dte_pdf_e($re['cod_prestacion']) ?></div><?php endif; ?>
  <?php else: ?>
    <div class="sm"><span class="muted">Receptor:</span> Consumidor Final</div>
  <?php endif; ?>
  <?php if (!empty($pr['nombre'])): ?>
    <div class="sm"><span class="muted">Profesional:</span> <b><?= dte_pdf_e($pr['nombre']) ?></b></div>
    <?php if (!empty($pr['rut'])): ?><div class="sm"><span class="muted">RUT:</span> <?= dte_pdf_e($pr['rut']) ?></div><?php endif; ?>
  <?php endif; ?>
  <?php if ($medio !== ''): ?><div class="sm"><span class="muted">Pago:</span> <?= dte_pdf_e($medio) ?></div><?php endif; ?>

  <div class="dash"></div>
  <table class="it">
    <?php foreach ($det as $l): ?>
      <tr><td class="l" colspan="2"><?= dte_pdf_e($l['descripcion']) ?><?= (int)($l['exento'] ?? 0) === 1 ? ' <span class="muted sm">(E)</span>' : '' ?></td></tr>
      <tr><td class="l muted sm"><?= dte_pdf_e(rtrim(rtrim(number_format((float)($l['cantidad'] ?? 1), 2, ',', '.'), '0'), ',')) ?> x <?= dte_pdf_money($l['precio'] ?? 0) ?></td><td class="r"><?= dte_pdf_money($l['total'] ?? 0) ?></td></tr>
    <?php endforeach; ?>
  </table>
  <div class="dash"></div>
  <?php if ($refs): ?>
    <div class="sm"><span class="muted">Referencia:</span><?php foreach ($refs as $rf): ?><br><?= dte_pdf_e($rf['texto'] ?? '') ?><?php endforeach; ?></div>
    <div class="dash"></div>
  <?php endif; ?>

  <table class="tot">
    <?php if ((int)($v['neto'] ?? 0) > 0): ?><tr><td class="muted">Neto</td><td class="r"><?= dte_pdf_money($v['neto']) ?></td></tr><?php endif; ?>
    <?php if ((int)($v['iva'] ?? 0) > 0): ?><tr><td class="muted">IVA 19%</td><td class="r"><?= dte_pdf_money($v['iva']) ?></td></tr><?php endif; ?>
    <?php if ((int)($v['exento'] ?? 0) > 0): ?><tr><td class="muted">Exento</td><td class="r"><?= dte_pdf_money($v['exento']) ?></td></tr><?php endif; ?>
    <tr class="g"><td>TOTAL</td><td class="r"><?= dte_pdf_money($v['total'] ?? 0) ?></td></tr>
  </table>

  <?php if ($observaciones !== ''): ?><div class="dash"></div><div class="sm"><span class="muted">Obs:</span> <?= dte_pdf_e($observaciones) ?></div><?php endif; ?>

  <?php if ($timbreUri !== ''): ?>
    <div class="dash"></div>
    <div class="timbre ctr">
      <img src="<?= $timbreUri ?>" alt="Timbre SII">
      <div class="muted sm">Timbre Electrónico SII</div>
      <?php if ($resLinea !== ''): ?><div class="muted sm"><?= dte_pdf_e($resLinea) ?></div><?php endif; ?>
      <div class="muted sm">Verifique en www.sii.cl</div>
    </div>
  <?php elseif ($mvpNote !== ''): ?>
    <div class="mvp muted"><?= $mvpNote ?></div>
  <?php endif; ?>
  <div class="dash"></div>
  <div class="ctr muted sm">¡Gracias por su preferencia!</div>
</body></html>
        <?php
        $html = ob_get_clean();

        $h = 150 + count($det) * (($formato === '58mm') ? 30 : 26) + ($timbreUri !== '' ? 165 : ($mvpNote !== '' ? 60 : 24)) + ($observaciones !== '' ? 30 : 0) + count($refs) * 34;
        // Líneas extra del receptor/profesional (dirección, cód. prestación, profesional, RUT).
        $extraLineas = (!empty($re['dir']) ? 1 : 0) + (!empty($re['cod_prestacion']) ? 1 : 0)
                     + (!empty($pr['nombre']) ? 1 : 0) + (!empty($pr['rut']) ? 1 : 0);
        $h += $extraLineas * (($formato === '58mm') ? 13 : 14);
        $h = max($h, 300.0);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper([0, 0, $w, $h]);
        $dompdf->render();

        $slug = preg_replace('/[^A-Za-z0-9]+/', '_', $nombre);
        return ['filename' => $slug . '_N' . $folio . '_' . $formato . '.pdf', 'bytes' => (string)$dompdf->output()];
    }

    // -------------------- CARTA (estilo Espacio Luz) --------------------
    ob_start(); ?>
<!doctype html><html lang="es"><head><meta charset="utf-8">
<style> * { font-family:'DejaVu Sans', Arial, sans-serif; } body { margin:0; } </style>
</head><body style="background:#fff;">
<div style="max-width:660px;margin:0 auto;background:#fff;border:1px solid <?= $linea ?>;border-radius:14px;overflow:hidden;color:<?= $cafe ?>;">
  <div style="height:6px;background:<?= $acc ?>;"></div>
  <div style="padding:26px 30px;">

    <table style="width:100%;border-collapse:collapse;"><tr>
      <td style="vertical-align:top;">
        <?php if ($logoUri !== ''): ?><img src="<?= $logoUri ?>" style="max-height:56px;max-width:180px;margin-bottom:8px;"><br><?php endif; ?>
        <div style="font-family:'DejaVu Serif',Georgia,serif;font-size:22px;font-weight:700;line-height:1.15;color:<?= $cafe ?>;"><?= dte_pdf_e($emisorNombre) ?></div>
        <?php if (!empty($em['fantasia']) && $em['fantasia'] !== $em['nombre']): ?><div style="font-size:12px;color:<?= $gris ?>;margin-top:2px;"><?= dte_pdf_e($em['fantasia']) ?></div><?php endif; ?>
        <div style="font-size:12px;color:<?= $gris ?>;margin-top:6px;line-height:1.55;">
          <?php if (!empty($em['giro'])): ?><?= dte_pdf_e($em['giro']) ?><br><?php endif; ?>
          <?php if (!empty($em['acteco'])): ?>Giro/Act.: <?= dte_pdf_e($em['acteco']) ?><br><?php endif; ?>
          <?php if (!empty($em['dir'])): ?><?= dte_pdf_e($em['dir']) ?><?= $loc !== '' ? ', ' . dte_pdf_e($loc) : '' ?><br><?php endif; ?>
          RUT: <?= dte_pdf_e($em['rut'] ?? '—') ?><?= !empty($em['email']) ? ' · ' . dte_pdf_e($em['email']) : '' ?>
        </div>
      </td>
      <td style="vertical-align:top;text-align:right;width:210px;">
        <div style="display:inline-block;border:2px solid <?= $acc ?>;border-radius:10px;padding:12px 18px;text-align:center;min-width:180px;">
          <div style="font-size:11px;letter-spacing:1px;font-weight:700;color:<?= $acc ?>;text-transform:uppercase;"><?= dte_pdf_e($tituloDoc) ?></div>
          <div style="font-size:22px;font-weight:800;color:<?= $cafe ?>;margin-top:2px;">N° <?= dte_pdf_e($folio) ?></div>
          <?php if ($fecha !== ''): ?><div style="font-size:11px;color:<?= $gris ?>;margin-top:4px;"><?= dte_pdf_e($fecha) ?></div><?php endif; ?>
          <?php if ($timbreUri !== ''): ?><div style="font-size:8px;color:<?= $gris ?>;margin-top:3px;text-transform:uppercase;letter-spacing:.5px;">Timbre Electrónico SII</div><?php endif; ?>
        </div>
      </td>
    </tr></table>

    <table style="width:100%;border-collapse:collapse;margin-top:22px;background:<?= $crema ?>;border-radius:10px;"><tr>
      <td style="padding:12px 16px;vertical-align:top;">
        <div style="font-size:10px;letter-spacing:.5px;text-transform:uppercase;color:<?= $gris ?>;font-weight:700;"><?= $esFactura ? 'Señor(es) / receptor' : (!empty($re['es_tutor']) ? 'Tutor / apoderado' : 'Paciente') ?></div>
        <div style="font-size:14px;font-weight:700;margin-top:3px;color:<?= $cafe ?>;"><?= dte_pdf_e($esCF ? 'Consumidor Final' : ($re['nombre'] ?? '—')) ?></div>
        <?php if (!$esCF): ?>
          <?php if (!empty($re['rut'])): ?><div style="font-size:12px;color:<?= $gris ?>;">RUT: <?= dte_pdf_e($re['rut']) ?></div><?php endif; ?>
          <?php if (!empty($re['giro'])): ?><div style="font-size:12px;color:<?= $gris ?>;">Giro: <?= dte_pdf_e($re['giro']) ?></div><?php endif; ?>
          <?php if (!empty($re['dir'])): ?><div style="font-size:12px;color:<?= $gris ?>;">Dirección: <?= dte_pdf_e($re['dir']) ?></div><?php endif; ?>
          <?php if (!empty($re['cod_prestacion'])): ?><div style="font-size:12px;color:<?= $gris ?>;">Cód. prestación: <?= dte_pdf_e($re['cod_prestacion']) ?></div><?php endif; ?>
          <?php if (!empty($re['email'])): ?><div style="font-size:12px;color:<?= $gris ?>;"><?= dte_pdf_e($re['email']) ?></div><?php endif; ?>
          <?php if (!empty($re['es_tutor']) && !empty($re['paciente'])): ?><div style="font-size:12px;color:<?= $cafe ?>;margin-top:4px;"><strong>Paciente atendido:</strong> <?= dte_pdf_e($re['paciente']) ?><?= !empty($re['paciente_rut']) ? ' · RUT ' . dte_pdf_e($re['paciente_rut']) : '' ?></div><?php endif; ?>
        <?php endif; ?>
        <?php if (!empty($pr['nombre'])): ?>
          <div style="margin-top:12px;padding-top:10px;border-top:1px solid <?= $linea ?>;">
            <div style="font-size:10px;letter-spacing:.5px;text-transform:uppercase;color:<?= $gris ?>;font-weight:700;">Profesional</div>
            <div style="font-size:14px;font-weight:700;margin-top:3px;color:<?= $cafe ?>;"><?= dte_pdf_e($pr['nombre']) ?></div>
            <?php if (!empty($pr['rut'])): ?><div style="font-size:12px;color:<?= $gris ?>;">RUT: <?= dte_pdf_e($pr['rut']) ?></div><?php endif; ?>
          </div>
        <?php endif; ?>
      </td>
      <td style="padding:12px 16px;vertical-align:top;text-align:right;">
        <?php if ($medio !== ''): ?>
          <div style="font-size:10px;letter-spacing:.5px;text-transform:uppercase;color:<?= $gris ?>;font-weight:700;">Medio de pago</div>
          <div style="font-size:14px;font-weight:700;margin-top:3px;color:<?= $cafe ?>;"><?= dte_pdf_e($medio) ?></div>
        <?php endif; ?>
        <?php if (!empty($v['emitida_por'])): ?><div style="font-size:11px;color:<?= $gris ?>;margin-top:4px;">Emitida por <?= dte_pdf_e($v['emitida_por']) ?></div><?php endif; ?>
        <?php if (!empty($v['trackid'])): ?><div style="font-size:11px;color:<?= $gris ?>;margin-top:4px;">TrackID SII: <?= dte_pdf_e($v['trackid']) ?></div><?php endif; ?>
      </td>
    </tr></table>

    <table style="width:100%;border-collapse:collapse;margin-top:20px;">
      <thead><tr style="border-bottom:2px solid <?= $linea ?>;">
        <th style="text-align:left;padding:8px 6px;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:<?= $gris ?>;">Detalle</th>
        <th style="text-align:center;padding:8px 6px;font-size:11px;text-transform:uppercase;color:<?= $gris ?>;">Cant.</th>
        <th style="text-align:right;padding:8px 6px;font-size:11px;text-transform:uppercase;color:<?= $gris ?>;"><?= ((int)($v['iva'] ?? 0) === 0 && (int)($v['neto'] ?? 0) === 0) ? 'Precio' : 'P. neto' ?></th>
        <th style="text-align:right;padding:8px 6px;font-size:11px;text-transform:uppercase;color:<?= $gris ?>;">Total</th>
      </tr></thead>
      <tbody>
        <?php foreach ($det as $l): ?>
        <tr style="border-bottom:1px solid <?= $linea ?>;">
          <td style="padding:9px 6px;font-size:13px;color:<?= $cafe ?>;"><?= dte_pdf_e($l['descripcion']) ?><?= (int)($l['exento'] ?? 0) === 1 ? ' <span style="font-size:10px;color:'.$gris.';">(exento)</span>' : '' ?></td>
          <td style="padding:9px 6px;font-size:13px;text-align:center;color:<?= $cafe ?>;"><?= dte_pdf_e(rtrim(rtrim(number_format((float)($l['cantidad'] ?? 1), 2, ',', '.'), '0'), ',')) ?></td>
          <td style="padding:9px 6px;font-size:13px;text-align:right;color:<?= $cafe ?>;"><?= dte_pdf_money($l['precio'] ?? 0) ?></td>
          <td style="padding:9px 6px;font-size:13px;text-align:right;font-weight:700;color:<?= $cafe ?>;"><?= dte_pdf_money($l['total'] ?? 0) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <?php if ($refs): ?>
      <table style="width:100%;border-collapse:collapse;margin-top:14px;background:<?= $crema ?>;border-radius:10px;">
        <tr><td style="padding:10px 14px;">
          <div style="font-size:10px;letter-spacing:.5px;text-transform:uppercase;color:<?= $gris ?>;font-weight:700;">Referencia</div>
          <?php foreach ($refs as $rf): ?><div style="font-size:12px;color:<?= $cafe ?>;margin-top:3px;"><?= dte_pdf_e($rf['texto'] ?? '') ?></div><?php endforeach; ?>
        </td></tr>
      </table>
    <?php endif; ?>

    <table style="width:100%;border-collapse:collapse;margin-top:14px;"><tr>
      <td></td>
      <td style="width:250px;">
        <?php if ((int)($v['neto'] ?? 0) > 0): ?>
          <table style="width:100%;font-size:13px;">
            <tr><td style="padding:3px 6px;color:<?= $gris ?>;">Neto</td><td style="padding:3px 6px;text-align:right;color:<?= $cafe ?>;"><?= dte_pdf_money($v['neto']) ?></td></tr>
            <tr><td style="padding:3px 6px;color:<?= $gris ?>;">IVA 19%</td><td style="padding:3px 6px;text-align:right;color:<?= $cafe ?>;"><?= dte_pdf_money($v['iva'] ?? 0) ?></td></tr>
          </table>
        <?php endif; ?>
        <?php if ((int)($v['exento'] ?? 0) > 0): ?>
          <table style="width:100%;font-size:13px;"><tr><td style="padding:3px 6px;color:<?= $gris ?>;">Exento</td><td style="padding:3px 6px;text-align:right;color:<?= $cafe ?>;"><?= dte_pdf_money($v['exento']) ?></td></tr></table>
        <?php endif; ?>
        <table style="width:100%;margin-top:6px;background:<?= $cafe ?>;border-radius:8px;">
          <tr><td style="padding:10px 12px;color:#fff;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">Total</td>
          <td style="padding:10px 12px;color:#fff;font-size:18px;font-weight:800;text-align:right;"><?= dte_pdf_money($v['total'] ?? 0) ?></td></tr>
        </table>
      </td>
    </tr></table>

    <?php if ($observaciones !== ''): ?>
      <div style="margin-top:14px;font-size:12px;color:<?= $gris ?>;"><strong>Observaciones:</strong> <?= dte_pdf_e($observaciones) ?></div>
    <?php endif; ?>

    <?php if (in_array($tipo, [33, 34], true) && $timbreUri !== ''): ?>
      <table style="width:100%;border-collapse:collapse;margin-top:20px;border:1.5px solid <?= $linea ?>;border-radius:12px;">
        <tr><td style="padding:12px 14px;">
          <div style="font-size:10px;letter-spacing:.6px;text-transform:uppercase;color:<?= $acc ?>;font-weight:700;">Acuse de Recibo</div>
          <table style="width:100%;margin-top:6px;"><tr>
            <td style="width:25%;padding-right:10px;"><div style="font-size:8px;color:<?= $gris ?>;text-transform:uppercase;">Nombre</div><div style="border-bottom:1px solid <?= $gris ?>;height:18px;"></div></td>
            <td style="width:25%;padding-right:10px;"><div style="font-size:8px;color:<?= $gris ?>;text-transform:uppercase;">RUT</div><div style="border-bottom:1px solid <?= $gris ?>;height:18px;"></div></td>
            <td style="width:25%;padding-right:10px;"><div style="font-size:8px;color:<?= $gris ?>;text-transform:uppercase;">Fecha</div><div style="border-bottom:1px solid <?= $gris ?>;height:18px;"></div></td>
            <td style="width:25%;"><div style="font-size:8px;color:<?= $gris ?>;text-transform:uppercase;">Recinto</div><div style="border-bottom:1px solid <?= $gris ?>;height:18px;"></div></td>
          </tr></table>
          <div style="font-size:7.5px;color:<?= $gris ?>;margin-top:8px;line-height:1.45;text-align:justify;">El acuse de recibo que se declara en este acto, de acuerdo a lo dispuesto en la letra b) del Art. 4° y la letra c) del Art. 5° de la Ley 19.983, acredita que la entrega de mercaderías o servicio(s) prestado(s) ha(n) sido recibido(s).</div>
        </td></tr>
      </table>
    <?php endif; ?>

    <?php if ($timbreUri !== ''): ?>
      <div style="text-align:center;margin-top:20px;">
        <img src="<?= $timbreUri ?>" alt="Timbre Electrónico SII" style="width:300px;max-width:90%;">
        <div style="font-family:'DejaVu Serif',serif;font-weight:700;font-size:11px;margin-top:6px;color:<?= $cafe ?>;">Timbre Electrónico SII</div>
        <?php if ($resLinea !== ''): ?><div style="font-size:10px;color:<?= $gris ?>;"><?= dte_pdf_e($resLinea) ?></div><?php endif; ?>
        <div style="font-size:10px;color:<?= $gris ?>;">Verifique documento: www.sii.cl</div>
      </div>
    <?php elseif ($mvpNote !== ''): ?>
      <div style="margin-top:22px;padding:10px 14px;background:#FBF3EC;border:1px dashed <?= $acc ?>;border-radius:8px;font-size:11px;color:<?= $cafe ?>;line-height:1.5;"><?= $mvpNote ?></div>
    <?php endif; ?>

  </div>
</div>
</body></html>
    <?php
    $html = ob_get_clean();

    $options = new Options();
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'DejaVu Sans');
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('letter', 'portrait');
    $dompdf->render();

    $canvas = $dompdf->getCanvas();
    $pie = ($timbreUri !== '' ? 'Documento Tributario Electrónico' : 'Documento interno (pendiente de timbrado SII)') . ' — ' . $emisorNombre . ' · ' . $nombre . ' N° ' . $folio;
    $canvas->page_text(36, 770, $pie, null, 7.5, [0.54, 0.49, 0.44]);
    $canvas->page_text(500, 770, 'Página {PAGE_NUM} de {PAGE_COUNT}', null, 7.5, [0.54, 0.49, 0.44]);

    $slug = preg_replace('/[^A-Za-z0-9]+/', '_', $nombre);
    return ['filename' => $slug . '_N' . $folio . '.pdf', 'bytes' => (string)$dompdf->output()];
}

/**
 * PDF de un DTE real (tabla dte_documentos) con Timbre PDF417.
 * @return array{filename:string, bytes:string}|null
 */
function dte_pdf_bytes(mysqli $conex, int $id, string $formato = 'carta'): ?array
{
    $v = dte_pdf_doc_v($conex, $id);
    return $v ? dte_pdf_render($v, $formato) : null;
}

/**
 * Datos normalizados ($v) de un DTE real (dte_documentos), con su timbre y sus referencias.
 * Los usan el PDF (dte_pdf_bytes) y la vista en pantalla (doc_preview_html): un solo formato.
 */
function dte_pdf_doc_v(mysqli $conex, int $id): ?array
{
    if ($id <= 0) return null;

    $st = $conex->prepare("SELECT d.*, em.razon_social AS emisor, em.nombre_fantasia AS emisor_fantasia,
                                  em.rut AS emisor_rut, em.giro AS emisor_giro, em.actividad_economica AS emisor_acteco,
                                  em.direccion AS emisor_dir, em.comuna AS emisor_comuna, em.ciudad AS emisor_ciudad,
                                  em.email AS emisor_email, em.sii_resolucion_numero AS em_res_num, em.sii_resolucion_fecha AS em_res_fecha,
                                  b.receptor_giro AS receptor_giro, cl.direccion AS receptor_dir, cl.comuna AS receptor_comuna, cl.email AS receptor_email,
                                  b.medio_pago AS medio_pago, b.emisor_nombre AS boleta_emisor,
                                  b.receptor_direccion AS b_cli_dir, b.receptor_comuna AS b_cli_comuna,
                                  b.receptor_codigo_prestacion AS b_cli_cod, cl.codigo_prestacion AS cl_cod,
                                  b.profesional_nombre AS prof_nombre, b.profesional_rut AS prof_rut,
                                  b.receptor_tipo AS b_rec_tipo, cl.nombre AS paciente_nombre, cl.rut AS paciente_rut
                           FROM dte_documentos d
                           LEFT JOIN empresas em ON em.id=d.empresa_id
                           LEFT JOIN pacientes cl ON cl.id=d.paciente_id
                           LEFT JOIN boletas  b  ON b.id=d.boleta_id
                           WHERE d.id=? LIMIT 1");
    $st->bind_param('i', $id); $st->execute();
    $doc = $st->get_result()->fetch_assoc(); $st->close();
    if (!$doc) return null;

    $det = [];
    $st = $conex->prepare("SELECT * FROM dte_documento_det WHERE documento_id=? ORDER BY id");
    $st->bind_param('i', $id); $st->execute();
    $rs = $st->get_result();
    while ($r = $rs->fetch_assoc()) {
        $det[] = ['descripcion' => $r['descripcion'], 'cantidad' => $r['cantidad'], 'precio' => $r['precio_unitario'], 'total' => $r['total_linea'], 'exento' => (int)$r['exento']];
    }
    $st->close();

    $xmlDte = '';
    if (!empty($doc['sii_envio_id'])) {
        $q = $conex->prepare("SELECT xml_dte FROM sii_envios WHERE id=? LIMIT 1");
        $envId = (int)$doc['sii_envio_id']; $q->bind_param('i', $envId); $q->execute();
        $xmlDte = (string)($q->get_result()->fetch_assoc()['xml_dte'] ?? ''); $q->close();
    }
    $timbreUri = $xmlDte !== '' ? dte_timbre_png_datauri($xmlDte) : '';

    $res = dte_resolucion();
    // Resolución propia de la empresa emisora (cada RUT tiene la suya).
    if (($doc['em_res_num'] ?? null) !== null && !empty($doc['em_res_fecha'])) {
        $res = ['numero' => (int) $doc['em_res_num'], 'fecha' => (string) $doc['em_res_fecha']];
    }
    $resLinea = '';
    if (!empty($res['numero'])) {
        $resLinea = 'Res. N° ' . (int)$res['numero'];
        if (!empty($res['fecha'])) { $ts = strtotime($res['fecha']); if ($ts) $resLinea .= ' de ' . date('d-m-Y', $ts); }
    }

    // Referencias (NC/ND): texto legible y, si el documento no tiene boleta propia, los datos del
    // receptor (giro, dirección, comuna, correo) se toman del documento que corrige.
    $codRefTxt = [1 => 'Anula documento', 2 => 'Corrige texto', 3 => 'Corrige montos'];
    $referencias = [];
    $st = $conex->prepare("SELECT tipo_doc, folio, fecha, codigo, razon FROM dte_documento_ref WHERE documento_id=? ORDER BY id");
    $st->bind_param('i', $id); $st->execute();
    $rsR = $st->get_result();
    $refDoc = null;
    while ($rf = $rsR->fetch_assoc()) {
        $tRef = (int)$rf['tipo_doc'];
        $fTs = !empty($rf['fecha']) ? strtotime((string)$rf['fecha']) : false;
        $referencias[] = ['texto' => dte_nombre($tRef) . ' N° ' . $rf['folio'] . ($fTs ? ' del ' . date('d-m-Y', $fTs) : '')
            . (!empty($rf['codigo']) ? ' · ' . ($codRefTxt[(int)$rf['codigo']] ?? ('Código ' . (int)$rf['codigo'])) : '')
            . (trim((string)$rf['razon']) !== '' ? ' · ' . trim((string)$rf['razon']) : '')];
        if ($refDoc === null && empty($doc['boleta_id'])) {
            $q = $conex->prepare("SELECT id, boleta_id FROM dte_documentos WHERE empresa_id=? AND tipo_dte=? AND folio=? ORDER BY id DESC LIMIT 1");
            $empQ = (int)$doc['empresa_id']; $folQ = (int)$rf['folio'];
            $q->bind_param('iii', $empQ, $tRef, $folQ); $q->execute();
            $refDoc = $q->get_result()->fetch_assoc() ?: null; $q->close();
        }
    }
    $st->close();
    // Una ND que anula una NC referencia a la NC: se sube un nivel hasta la factura original.
    for ($salto = 0; $refDoc && empty($refDoc['boleta_id']) && $salto < 3; $salto++) {
        $q = $conex->prepare("SELECT d.id, d.boleta_id FROM dte_documento_ref r JOIN dte_documentos d ON d.empresa_id=? AND d.tipo_dte=CAST(r.tipo_doc AS UNSIGNED) AND d.folio=CAST(r.folio AS UNSIGNED) WHERE r.documento_id=? ORDER BY r.id LIMIT 1");
        $empQ = (int)$doc['empresa_id']; $idQ = (int)$refDoc['id'];
        $q->bind_param('ii', $empQ, $idQ); $q->execute();
        $refDoc = $q->get_result()->fetch_assoc() ?: null; $q->close();
    }
    if ($refDoc && !empty($refDoc['boleta_id'])) {
        $q = $conex->prepare("SELECT receptor_giro, receptor_direccion, receptor_comuna, receptor_email, medio_pago FROM boletas WHERE id=? LIMIT 1");
        $bId = (int)$refDoc['boleta_id']; $q->bind_param('i', $bId); $q->execute();
        $rb = $q->get_result()->fetch_assoc() ?: []; $q->close();
        if (empty($doc['receptor_giro']))  $doc['receptor_giro']  = $rb['receptor_giro'] ?? null;
        if (empty($doc['b_cli_dir']))      $doc['b_cli_dir']      = $rb['receptor_direccion'] ?? null;
        if (empty($doc['b_cli_comuna']))   $doc['b_cli_comuna']   = $rb['receptor_comuna'] ?? null;
        if (empty($doc['receptor_email'])) $doc['receptor_email'] = $rb['receptor_email'] ?? null;
    }

    $extra = !empty($doc['datos_extra']) ? json_decode((string)$doc['datos_extra'], true) : null;
    $obs = (is_array($extra) && !empty($extra['observaciones'])) ? (string)$extra['observaciones'] : '';
    $tipo = (int)$doc['tipo_dte'];
    $esFactura = in_array($tipo, [33, 34, 56, 61], true);
    $esCF = (!$esFactura && (empty($doc['rut_receptor']) || $doc['rut_receptor'] === '66666666-6'));
    $loc = trim((string)($doc['emisor_comuna'] ?? '') . (!empty($doc['emisor_ciudad']) ? ', ' . $doc['emisor_ciudad'] : ''));
    // Dirección / comuna / cód. prestación: lo guardado en la boleta; si no, la ficha del paciente.
    $dirTxt = trim((string)($doc['b_cli_dir'] ?? '')) ?: trim((string)($doc['receptor_dir'] ?? ''));
    $comTxt = trim((string)($doc['b_cli_comuna'] ?? '')) ?: trim((string)($doc['receptor_comuna'] ?? ''));
    $codTxt = trim((string)($doc['b_cli_cod'] ?? '')) ?: trim((string)($doc['cl_cod'] ?? ''));
    $rdir = trim($dirTxt . ($comTxt !== '' ? ($dirTxt !== '' ? ', ' : '') . $comTxt : ''));
    $ts = strtotime((string)$doc['fecha_emision']);

    $v = [
        'tipo'  => $tipo,
        'folio' => str_pad((string)$doc['folio'], 6, '0', STR_PAD_LEFT),
        'fecha' => $ts ? date('d-m-Y', $ts) : (string)$doc['fecha_emision'],
        'emisor' => [
            'nombre' => $doc['emisor'] ?: $doc['emisor_fantasia'], 'fantasia' => $doc['emisor_fantasia'],
            'rut' => $doc['emisor_rut'], 'giro' => $doc['emisor_giro'], 'acteco' => $doc['emisor_acteco'],
            'dir' => $doc['emisor_dir'], 'loc' => $loc, 'email' => $doc['emisor_email'],
        ],
        'receptor' => [
            'es_cf' => $esCF, 'nombre' => $doc['razon_receptor'], 'rut' => $doc['rut_receptor'],
            'giro' => $doc['receptor_giro'], 'dir' => $rdir, 'email' => $doc['receptor_email'],
            'cod_prestacion' => $codTxt,
            'es_tutor' => ($doc['b_rec_tipo'] ?? '') === 'tutor',
            'paciente' => (string)($doc['paciente_nombre'] ?? ''), 'paciente_rut' => (string)($doc['paciente_rut'] ?? ''),
        ],
        'profesional' => ['nombre' => (string)($doc['prof_nombre'] ?? ''), 'rut' => (string)($doc['prof_rut'] ?? '')],
        'medio' => dte_pdf_medio($doc['medio_pago'] ?? null),
        'emitida_por' => $doc['boleta_emisor'] ?? '',
        'trackid' => $doc['trackid'] ?? '',
        'items' => $det,
        'neto' => (int)$doc['neto'], 'iva' => (int)$doc['iva'], 'exento' => (int)$doc['exento'], 'total' => (int)$doc['total'],
        'observaciones' => $obs,
        'timbre_uri' => $timbreUri, 'res_linea' => $resLinea, 'mvp_note' => '',
        'referencias' => $referencias,
    ];
    return $v;
}

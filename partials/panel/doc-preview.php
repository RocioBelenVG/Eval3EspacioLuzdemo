<?php
/**
 * Vista previa HTML de un documento (boleta/factura) en pantalla, en los 3
 * formatos (carta / 80mm / 58mm). Mismo estilo que el PDF pero con las fuentes
 * reales de Espacio Luz y responsivo. NO usa el visor de PDF del navegador.
 *
 * Provee doc_preview_html(array $v, string $formato): string  (HTML interno).
 * $v tiene la misma forma que arma boleta_doc_v() / dte_pdf_bytes().
 */
declare(strict_types=1);

if (!function_exists('doc_preview_html')) {
    function doc_preview_html(array $v, string $formato = 'carta'): string
    {
        $formato = in_array($formato, ['carta', '80mm', '58mm'], true) ? $formato : 'carta';
        $tipo = (int)($v['tipo'] ?? 39);
        // Facturas y notas de crédito/débito llevan receptor completo (igual que el PDF).
        $esFactura = in_array($tipo, [33, 34, 56, 61], true);
        $acc = $esFactura ? '#B85C3E' : '#6D8072';
        $titulo = [33 => 'Factura electrónica', 34 => 'Factura no afecta o exenta electrónica', 39 => 'Boleta electrónica', 41 => 'Boleta exenta electrónica',
                   56 => 'Nota de débito electrónica', 61 => 'Nota de crédito electrónica'][$tipo] ?? ($esFactura ? 'Factura electrónica' : 'Boleta electrónica');
        $refs = is_array($v['referencias'] ?? null) ? $v['referencias'] : [];
        $cafe = '#5C4033'; $crema = '#F5F1E8'; $linea = '#E4DAC9'; $gris = '#8a7c70';

        $em = $v['emisor'] ?? []; $re = $v['receptor'] ?? []; $det = $v['items'] ?? [];
        $pr = $v['profesional'] ?? [];
        $folio = (string)($v['folio'] ?? ''); $fecha = (string)($v['fecha'] ?? '');
        $loc = (string)($em['loc'] ?? ''); $esCF = !empty($re['es_cf']);
        $medio = (string)($v['medio'] ?? '');
        $timbre = (string)($v['timbre_uri'] ?? ''); $resLinea = (string)($v['res_linea'] ?? '');
        $obs = (string)($v['observaciones'] ?? ''); $mvp = (string)($v['mvp_note'] ?? '');
        $emNombre = (string)($em['nombre'] ?? '');
        $logo = asset('images/logo/logo.png');
        $m = static fn($x) => clp((int)$x);

        ob_start();

        // ---------------- TICKET 80 / 58 mm ----------------
        if ($formato === '80mm' || $formato === '58mm') {
            $w = $formato === '58mm' ? 240 : 300;
            $fs = $formato === '58mm' ? 11.5 : 12.5;
            ?>
            <div style="width:<?= $w ?>px;max-width:100%;margin:0 auto;background:#fff;border:1px solid <?= $linea ?>;border-radius:10px;box-shadow:0 14px 34px -16px rgba(92,64,51,.3);padding:18px 16px;font-family:'Nunito',sans-serif;color:<?= $cafe ?>;font-size:<?= $fs ?>px;line-height:1.45;">
                <div style="text-align:center;">
                    <?php if (hasLogo()): ?><img src="<?= e($logo) ?>" alt="" style="height:40px;width:auto;margin-bottom:6px;border-radius:50%;"><br><?php endif; ?>
                    <div style="font-family:'Playfair Display',serif;font-weight:700;font-size:<?= $fs + 3 ?>px;"><?= e($emNombre) ?></div>
                    <?php if (!empty($em['rut'])): ?><div style="color:<?= $gris ?>;font-size:<?= $fs - 1.5 ?>px;">RUT: <?= e($em['rut']) ?></div><?php endif; ?>
                    <?php if (!empty($em['giro'])): ?><div style="color:<?= $gris ?>;font-size:<?= $fs - 1.5 ?>px;"><?= e($em['giro']) ?></div><?php endif; ?>
                    <?php if (!empty($em['dir'])): ?><div style="color:<?= $gris ?>;font-size:<?= $fs - 1.5 ?>px;"><?= e($em['dir']) ?><?= $loc !== '' ? ', ' . e($loc) : '' ?></div><?php endif; ?>
                </div>
                <div style="border:1.3px solid <?= $acc ?>;border-radius:7px;padding:6px 4px;margin:9px 0;text-align:center;">
                    <div style="font-weight:800;color:<?= $acc ?>;text-transform:uppercase;letter-spacing:.5px;font-size:<?= $fs ?>px;"><?= e($titulo) ?></div>
                    <div style="font-weight:800;font-size:<?= $fs + 2 ?>px;">N° <?= e($folio) ?></div>
                    <?php if ($fecha !== ''): ?><div style="color:<?= $gris ?>;font-size:<?= $fs - 1.5 ?>px;"><?= e($fecha) ?></div><?php endif; ?>
                </div>
                <?php if (!$esCF): ?>
                    <div style="font-size:<?= $fs - 1 ?>px;"><span style="color:<?= $gris ?>;">Receptor:</span> <b><?= e($re['nombre'] ?? '—') ?></b></div>
                    <?php if (!empty($re['rut'])): ?><div style="font-size:<?= $fs - 1 ?>px;"><span style="color:<?= $gris ?>;">RUT:</span> <?= e($re['rut']) ?></div><?php endif; ?>
                    <?php if (!empty($re['dir'])): ?><div style="font-size:<?= $fs - 1 ?>px;"><span style="color:<?= $gris ?>;">Dir:</span> <?= e($re['dir']) ?></div><?php endif; ?>
                    <?php if (!empty($re['cod_prestacion'])): ?><div style="font-size:<?= $fs - 1 ?>px;"><span style="color:<?= $gris ?>;">Cód. prest.:</span> <?= e($re['cod_prestacion']) ?></div><?php endif; ?>
                <?php else: ?>
                    <div style="font-size:<?= $fs - 1 ?>px;"><span style="color:<?= $gris ?>;">Receptor:</span> Consumidor Final</div>
                <?php endif; ?>
                <?php if (!empty($pr['nombre'])): ?>
                    <div style="font-size:<?= $fs - 1 ?>px;margin-top:3px;"><span style="color:<?= $gris ?>;">Profesional:</span> <b><?= e($pr['nombre']) ?></b></div>
                    <?php if (!empty($pr['rut'])): ?><div style="font-size:<?= $fs - 1 ?>px;"><span style="color:<?= $gris ?>;">RUT:</span> <?= e($pr['rut']) ?></div><?php endif; ?>
                <?php endif; ?>
                <?php if ($medio !== ''): ?><div style="font-size:<?= $fs - 1 ?>px;"><span style="color:<?= $gris ?>;">Pago:</span> <?= e($medio) ?></div><?php endif; ?>

                <div style="border-top:1px dashed <?= $gris ?>;opacity:.5;margin:7px 0;"></div>
                <?php foreach ($det as $l): ?>
                    <div style="font-size:<?= $fs - .5 ?>px;"><?= e($l['descripcion']) ?><?= (int)($l['exento'] ?? 0) === 1 ? ' <span style="color:'.$gris.';font-size:'.($fs-2).'px;">(E)</span>' : '' ?></div>
                    <table style="width:100%;border-collapse:collapse;"><tr>
                        <td style="color:<?= $gris ?>;font-size:<?= $fs - 1.5 ?>px;"><?= e(rtrim(rtrim(number_format((float)($l['cantidad'] ?? 1), 2, ',', '.'), '0'), ',')) ?> x <?= $m($l['precio'] ?? 0) ?></td>
                        <td style="text-align:right;"><?= $m($l['total'] ?? 0) ?></td>
                    </tr></table>
                <?php endforeach; ?>
                <div style="border-top:1px dashed <?= $gris ?>;opacity:.5;margin:7px 0;"></div>
                <?php if ($refs): ?>
                    <div style="font-size:<?= $fs - 1 ?>px;"><span style="color:<?= $gris ?>;">Referencia:</span><?php foreach ($refs as $rf): ?><br><?= e($rf['texto'] ?? '') ?><?php endforeach; ?></div>
                    <div style="border-top:1px dashed <?= $gris ?>;opacity:.5;margin:7px 0;"></div>
                <?php endif; ?>

                <table style="width:100%;border-collapse:collapse;">
                    <?php if ((int)($v['neto'] ?? 0) > 0): ?><tr><td style="color:<?= $gris ?>;">Neto</td><td style="text-align:right;"><?= $m($v['neto']) ?></td></tr><?php endif; ?>
                    <?php if ((int)($v['iva'] ?? 0) > 0): ?><tr><td style="color:<?= $gris ?>;">IVA 19%</td><td style="text-align:right;"><?= $m($v['iva']) ?></td></tr><?php endif; ?>
                    <?php if ((int)($v['exento'] ?? 0) > 0): ?><tr><td style="color:<?= $gris ?>;">Exento</td><td style="text-align:right;"><?= $m($v['exento']) ?></td></tr><?php endif; ?>
                    <tr><td style="font-weight:800;font-size:<?= $fs + 2 ?>px;border-top:1px solid <?= $linea ?>;padding-top:3px;">TOTAL</td><td style="text-align:right;font-weight:800;font-size:<?= $fs + 2 ?>px;border-top:1px solid <?= $linea ?>;padding-top:3px;"><?= $m($v['total'] ?? 0) ?></td></tr>
                </table>

                <?php if ($obs !== ''): ?><div style="border-top:1px dashed <?= $gris ?>;opacity:.5;margin:7px 0;"></div><div style="font-size:<?= $fs - 1 ?>px;"><span style="color:<?= $gris ?>;">Obs:</span> <?= e($obs) ?></div><?php endif; ?>

                <?php if ($timbre !== ''): ?>
                    <div style="border-top:1px dashed <?= $gris ?>;opacity:.5;margin:7px 0;"></div>
                    <div style="text-align:center;"><img src="<?= e($timbre) ?>" alt="Timbre" style="width:<?= $w - 40 ?>px;max-width:100%;"><div style="color:<?= $gris ?>;font-size:<?= $fs - 2 ?>px;">Timbre Electrónico SII</div><?php if ($resLinea !== ''): ?><div style="color:<?= $gris ?>;font-size:<?= $fs - 2 ?>px;"><?= e($resLinea) ?></div><?php endif; ?><div style="color:<?= $gris ?>;font-size:<?= $fs - 2 ?>px;">Verifique en www.sii.cl</div></div>
                <?php elseif ($mvp !== ''): ?>
                    <div style="border:1px dashed <?= $acc ?>;border-radius:6px;padding:6px 8px;margin-top:8px;font-size:<?= $fs - 2 ?>px;color:<?= $gris ?>;"><?= $mvp ?></div>
                <?php endif; ?>
                <div style="border-top:1px dashed <?= $gris ?>;opacity:.5;margin:7px 0;"></div>
                <div style="text-align:center;color:<?= $gris ?>;font-size:<?= $fs - 1 ?>px;">¡Gracias por su preferencia!</div>
            </div>
            <?php
            return ob_get_clean();
        }

        // ---------------- CARTA ----------------
        ?>
        <div style="max-width:660px;margin:0 auto;background:#fff;border:1px solid <?= $linea ?>;border-radius:14px;overflow:hidden;font-family:'Nunito',sans-serif;color:<?= $cafe ?>;">
            <div style="height:6px;background:<?= $acc ?>;"></div>
            <div style="padding:26px 30px;">
                <table style="width:100%;border-collapse:collapse;"><tr>
                    <td style="vertical-align:top;">
                        <?php if (hasLogo()): ?><img src="<?= e($logo) ?>" alt="" style="height:54px;width:auto;border-radius:50%;margin-bottom:8px;"><br><?php endif; ?>
                        <div style="font-family:'Playfair Display',serif;font-size:23px;font-weight:700;line-height:1.1;"><?= e($emNombre) ?></div>
                        <div style="font-size:12px;color:<?= $gris ?>;margin-top:6px;line-height:1.55;">
                            <?php if (!empty($em['giro'])): ?><?= e($em['giro']) ?><br><?php endif; ?>
                            <?php if (!empty($em['acteco'])): ?>Giro/Act.: <?= e($em['acteco']) ?><br><?php endif; ?>
                            <?php if (!empty($em['dir'])): ?><?= e($em['dir']) ?><?= $loc !== '' ? ', ' . e($loc) : '' ?><br><?php endif; ?>
                            RUT: <?= e($em['rut'] ?? '—') ?><?= !empty($em['email']) ? ' · ' . e($em['email']) : '' ?>
                        </div>
                    </td>
                    <td style="vertical-align:top;text-align:right;width:210px;">
                        <div style="display:inline-block;border:2px solid <?= $acc ?>;border-radius:10px;padding:12px 18px;text-align:center;min-width:180px;">
                            <div style="font-size:11px;letter-spacing:1px;font-weight:700;color:<?= $acc ?>;text-transform:uppercase;"><?= e($titulo) ?></div>
                            <div style="font-size:22px;font-weight:800;margin-top:2px;">N° <?= e($folio) ?></div>
                            <?php if ($fecha !== ''): ?><div style="font-size:11px;color:<?= $gris ?>;margin-top:4px;"><?= e($fecha) ?></div><?php endif; ?>
                            <?php if ($timbre !== ''): ?><div style="font-size:8px;color:<?= $gris ?>;margin-top:3px;text-transform:uppercase;letter-spacing:.5px;">Timbre Electrónico SII</div><?php endif; ?>
                        </div>
                    </td>
                </tr></table>

                <table style="width:100%;border-collapse:collapse;margin-top:22px;background:<?= $crema ?>;border-radius:10px;"><tr>
                    <td style="padding:12px 16px;vertical-align:top;">
                        <div style="font-size:10px;letter-spacing:.5px;text-transform:uppercase;color:<?= $gris ?>;font-weight:700;"><?= $esFactura ? 'Señor(es) / receptor' : 'Paciente' ?></div>
                        <div style="font-size:14px;font-weight:700;margin-top:3px;"><?= e($esCF ? 'Consumidor Final' : ($re['nombre'] ?? '—')) ?></div>
                        <?php if (!$esCF): ?>
                            <?php if (!empty($re['rut'])): ?><div style="font-size:12px;color:<?= $gris ?>;">RUT: <?= e($re['rut']) ?></div><?php endif; ?>
                            <?php if (!empty($re['giro'])): ?><div style="font-size:12px;color:<?= $gris ?>;">Giro: <?= e($re['giro']) ?></div><?php endif; ?>
                            <?php if (!empty($re['dir'])): ?><div style="font-size:12px;color:<?= $gris ?>;">Dirección: <?= e($re['dir']) ?></div><?php endif; ?>
                            <?php if (!empty($re['cod_prestacion'])): ?><div style="font-size:12px;color:<?= $gris ?>;">Cód. prestación: <?= e($re['cod_prestacion']) ?></div><?php endif; ?>
                            <?php if (!empty($re['email'])): ?><div style="font-size:12px;color:<?= $gris ?>;"><?= e($re['email']) ?></div><?php endif; ?>
                        <?php endif; ?>
                        <?php if (!empty($pr['nombre'])): ?>
                            <div style="margin-top:12px;padding-top:10px;border-top:1px solid <?= $linea ?>;">
                                <div style="font-size:10px;letter-spacing:.5px;text-transform:uppercase;color:<?= $gris ?>;font-weight:700;">Profesional</div>
                                <div style="font-size:14px;font-weight:700;margin-top:3px;"><?= e($pr['nombre']) ?></div>
                                <?php if (!empty($pr['rut'])): ?><div style="font-size:12px;color:<?= $gris ?>;">RUT: <?= e($pr['rut']) ?></div><?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td style="padding:12px 16px;vertical-align:top;text-align:right;">
                        <?php if ($medio !== ''): ?><div style="font-size:10px;letter-spacing:.5px;text-transform:uppercase;color:<?= $gris ?>;font-weight:700;">Medio de pago</div><div style="font-size:14px;font-weight:700;margin-top:3px;"><?= e($medio) ?></div><?php endif; ?>
                        <?php if (!empty($v['emitida_por'])): ?><div style="font-size:11px;color:<?= $gris ?>;margin-top:4px;">Emitida por <?= e($v['emitida_por']) ?></div><?php endif; ?>
                        <?php if (!empty($v['trackid'])): ?><div style="font-size:11px;color:<?= $gris ?>;margin-top:4px;">TrackID SII: <?= e($v['trackid']) ?></div><?php endif; ?>
                    </td>
                </tr></table>

                <table style="width:100%;border-collapse:collapse;margin-top:20px;">
                    <thead><tr style="border-bottom:2px solid <?= $linea ?>;">
                        <th style="text-align:left;padding:8px 6px;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:<?= $gris ?>;">Detalle</th>
                        <th style="text-align:center;padding:8px 6px;font-size:11px;text-transform:uppercase;color:<?= $gris ?>;">Cant.</th>
                        <th style="text-align:right;padding:8px 6px;font-size:11px;text-transform:uppercase;color:<?= $gris ?>;">P. neto</th>
                        <th style="text-align:right;padding:8px 6px;font-size:11px;text-transform:uppercase;color:<?= $gris ?>;">Total</th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($det as $l): ?>
                        <tr style="border-bottom:1px solid <?= $linea ?>;">
                            <td style="padding:9px 6px;font-size:13px;"><?= e($l['descripcion']) ?><?= (int)($l['exento'] ?? 0) === 1 ? ' <span style="font-size:10px;color:'.$gris.';">(exento)</span>' : '' ?></td>
                            <td style="padding:9px 6px;font-size:13px;text-align:center;"><?= e(rtrim(rtrim(number_format((float)($l['cantidad'] ?? 1), 2, ',', '.'), '0'), ',')) ?></td>
                            <td style="padding:9px 6px;font-size:13px;text-align:right;"><?= $m($l['precio'] ?? 0) ?></td>
                            <td style="padding:9px 6px;font-size:13px;text-align:right;font-weight:700;"><?= $m($l['total'] ?? 0) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ($refs): ?>
                    <table style="width:100%;border-collapse:collapse;margin-top:14px;background:<?= $crema ?>;border-radius:10px;"><tr><td style="padding:10px 14px;">
                        <div style="font-size:10px;letter-spacing:.5px;text-transform:uppercase;color:<?= $gris ?>;font-weight:700;">Referencia</div>
                        <?php foreach ($refs as $rf): ?><div style="font-size:12px;margin-top:3px;"><?= e($rf['texto'] ?? '') ?></div><?php endforeach; ?>
                    </td></tr></table>
                <?php endif; ?>

                <table style="width:100%;border-collapse:collapse;margin-top:14px;"><tr>
                    <td></td>
                    <td style="width:250px;">
                        <?php if ((int)($v['neto'] ?? 0) > 0): ?>
                            <table style="width:100%;font-size:13px;"><tr><td style="padding:3px 6px;color:<?= $gris ?>;">Neto</td><td style="padding:3px 6px;text-align:right;"><?= $m($v['neto']) ?></td></tr>
                            <tr><td style="padding:3px 6px;color:<?= $gris ?>;">IVA 19%</td><td style="padding:3px 6px;text-align:right;"><?= $m($v['iva'] ?? 0) ?></td></tr></table>
                        <?php endif; ?>
                        <?php if ((int)($v['exento'] ?? 0) > 0): ?>
                            <table style="width:100%;font-size:13px;"><tr><td style="padding:3px 6px;color:<?= $gris ?>;">Exento</td><td style="padding:3px 6px;text-align:right;"><?= $m($v['exento']) ?></td></tr></table>
                        <?php endif; ?>
                        <table style="width:100%;margin-top:6px;background:<?= $cafe ?>;border-radius:8px;"><tr>
                            <td style="padding:10px 12px;color:#fff;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">Total</td>
                            <td style="padding:10px 12px;color:#fff;font-size:18px;font-weight:800;text-align:right;"><?= $m($v['total'] ?? 0) ?></td>
                        </tr></table>
                    </td>
                </tr></table>

                <?php if ($obs !== ''): ?><div style="margin-top:14px;font-size:12px;color:<?= $gris ?>;"><strong>Observaciones:</strong> <?= e($obs) ?></div><?php endif; ?>

                <?php if (in_array($tipo, [33, 34], true) && $timbre !== ''): ?>
                    <div style="margin-top:20px;border:1.5px solid <?= $linea ?>;border-radius:12px;padding:12px 14px;">
                        <div style="font-size:10px;letter-spacing:.6px;text-transform:uppercase;color:<?= $acc ?>;font-weight:700;">Acuse de Recibo</div>
                        <table style="width:100%;margin-top:6px;"><tr>
                            <td style="width:25%;padding-right:10px;"><div style="font-size:8px;color:<?= $gris ?>;text-transform:uppercase;">Nombre</div><div style="border-bottom:1px solid <?= $gris ?>;height:18px;"></div></td>
                            <td style="width:25%;padding-right:10px;"><div style="font-size:8px;color:<?= $gris ?>;text-transform:uppercase;">RUT</div><div style="border-bottom:1px solid <?= $gris ?>;height:18px;"></div></td>
                            <td style="width:25%;padding-right:10px;"><div style="font-size:8px;color:<?= $gris ?>;text-transform:uppercase;">Fecha</div><div style="border-bottom:1px solid <?= $gris ?>;height:18px;"></div></td>
                            <td style="width:25%;"><div style="font-size:8px;color:<?= $gris ?>;text-transform:uppercase;">Recinto</div><div style="border-bottom:1px solid <?= $gris ?>;height:18px;"></div></td>
                        </tr></table>
                        <div style="font-size:7.5px;color:<?= $gris ?>;margin-top:8px;line-height:1.45;text-align:justify;">El acuse de recibo que se declara en este acto, de acuerdo a lo dispuesto en la letra b) del Art. 4° y la letra c) del Art. 5° de la Ley 19.983, acredita que la entrega de mercaderías o servicio(s) prestado(s) ha(n) sido recibido(s).</div>
                    </div>
                <?php endif; ?>

                <?php if ($timbre !== ''): ?>
                    <div style="text-align:center;margin-top:20px;">
                        <img src="<?= e($timbre) ?>" alt="Timbre Electrónico SII" style="width:300px;max-width:90%;">
                        <div style="font-family:'Playfair Display',serif;font-weight:700;font-size:11px;margin-top:6px;">Timbre Electrónico SII</div>
                        <?php if ($resLinea !== ''): ?><div style="font-size:10px;color:<?= $gris ?>;"><?= e($resLinea) ?></div><?php endif; ?>
                        <div style="font-size:10px;color:<?= $gris ?>;">Verifique documento: www.sii.cl</div>
                    </div>
                <?php elseif ($mvp !== ''): ?>
                    <div style="margin-top:22px;padding:10px 14px;background:#FBF3EC;border:1px dashed <?= $acc ?>;border-radius:8px;font-size:11px;color:<?= $cafe ?>;line-height:1.5;"><?= $mvp ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}

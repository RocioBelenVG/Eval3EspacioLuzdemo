<?php
/**
 * Documento imprimible (boleta o factura). Estilos EN LÍNEA para que se vea
 * igual en pantalla, al imprimir/guardar como PDF y dentro de un correo.
 * El tipo y el emisor se toman de la empresa asociada al documento.
 * Provee render_boleta_doc(array $b): string.
 */
declare(strict_types=1);

require_once APP_ROOT . '/lib/boletas.php';
require_once APP_ROOT . '/lib/pacientes.php';

if (!function_exists('render_boleta_doc')) {
    function render_boleta_doc(array $b): string
    {
        $em = emisorDeBoleta($b);
        $tipo = ($b['tipo'] ?? 'boleta') === 'factura' ? 'factura' : 'boleta';
        $esFactura = $tipo === 'factura';
        $fecha = date('d-m-Y H:i', strtotime($b['fecha_emision']));
        $folio = str_pad((string) $b['folio'], 6, '0', STR_PAD_LEFT);
        $C = ['cafe' => '#5C4033', 'terr' => '#D97757', 'crema' => '#F5F1E8', 'linea' => '#E4DAC9', 'gris' => '#8a7c70'];
        // Color del recuadro del folio: terracota para factura, salvia para boleta.
        $acc = $esFactura ? '#B85C3E' : '#6D8072';
        $tituloDoc = $esFactura ? ((int) ($b['neto_clp'] ?? 0) > 0 ? 'Factura electrónica' : 'Factura exenta electrónica') : 'Boleta electrónica';

        // Datos extra del receptor (dirección, comuna, cód. prestación y, en factura, giro)
        // desde la ficha del paciente seleccionado.
        $cli = !empty($b['paciente_id']) ? getPaciente((int) $b['paciente_id']) : null;
        // Factura: el receptor es un cliente (giro guardado en el documento; respaldo: su ficha).
        if ($esFactura && !empty($b['cliente_id'])) {
            require_once APP_ROOT . '/lib/clientes.php';
            $cli = getCliente((int) $b['cliente_id']);
        }
        $giroRec = trim((string) ($b['receptor_giro'] ?? '')) ?: trim((string) ($cli['giro'] ?? ''));

        ob_start(); ?>
<div style="max-width:640px;margin:0 auto;background:#fff;border:1px solid <?= $C['linea'] ?>;border-radius:14px;overflow:hidden;font-family:'Nunito',Arial,sans-serif;color:<?= $C['cafe'] ?>;">
    <div style="height:6px;background:<?= $acc ?>;"></div>
    <div style="padding:26px 30px;">
        <!-- Cabecera emisor -->
        <table style="width:100%;border-collapse:collapse;"><tr>
            <td style="vertical-align:top;">
                <div style="font-family:'Playfair Display',Georgia,serif;font-size:24px;font-weight:700;line-height:1.1;color:<?= $C['cafe'] ?>;"><?= e($em['razon_social'] ?? $em['nombre']) ?></div>
                <div style="font-size:12px;color:<?= $C['gris'] ?>;margin-top:6px;line-height:1.5;">
                    <?= e($em['giro']) ?><br>
                    <?php if (!empty($em['actividad_economica'])): ?>Giro/Act.: <?= e($em['actividad_economica']) ?><br><?php endif; ?>
                    <?= e($em['direccion']) ?><?= $em['comuna'] ? ', ' . e($em['comuna']) : '' ?><br>
                    RUT: <?= e($em['rut']) ?><?= $em['email'] ? ' · ' . e($em['email']) : '' ?>
                </div>
            </td>
            <td style="vertical-align:top;text-align:right;width:210px;">
                <div style="display:inline-block;border:2px solid <?= $acc ?>;border-radius:10px;padding:12px 18px;text-align:center;min-width:180px;">
                    <div style="font-size:11px;letter-spacing:1px;font-weight:700;color:<?= $acc ?>;text-transform:uppercase;"><?= e($tituloDoc) ?></div>
                    <div style="font-size:22px;font-weight:800;color:<?= $C['cafe'] ?>;margin-top:2px;">N° <?= e($folio) ?></div>
                    <div style="font-size:11px;color:<?= $C['gris'] ?>;margin-top:4px;"><?= e($fecha) ?></div>
                </div>
            </td>
        </tr></table>

        <!-- Receptor + pago -->
        <table style="width:100%;border-collapse:collapse;margin-top:22px;background:<?= $C['crema'] ?>;border-radius:10px;">
            <tr>
                <td style="padding:12px 16px;vertical-align:top;">
                    <div style="font-size:10px;letter-spacing:.5px;text-transform:uppercase;color:<?= $C['gris'] ?>;font-weight:700;"><?php $esTutor = ($b['receptor_tipo'] ?? '') === 'tutor'; ?><?= $esFactura ? 'Señor(es) / receptor' : ($esTutor ? 'Tutor / apoderado' : 'Paciente') ?></div>
                    <div style="font-size:14px;font-weight:700;margin-top:3px;"><?= e($b['receptor_nombre']) ?></div>
                    <?php if (!empty($b['receptor_rut'])): ?><div style="font-size:12px;color:<?= $C['gris'] ?>;">RUT: <?= e($b['receptor_rut']) ?></div><?php endif; ?>
                    <?php if ($esFactura && $giroRec !== ''): ?><div style="font-size:12px;color:<?= $C['gris'] ?>;">Giro: <?= e($giroRec) ?></div><?php endif; ?>
                    <?php
                    $dDir = trim((string) ($b['receptor_direccion'] ?? '')) ?: trim((string) ($cli['direccion'] ?? ''));
                    $dCom = trim((string) ($b['receptor_comuna'] ?? '')) ?: trim((string) ($cli['comuna'] ?? ''));
                    $dCod = $esFactura ? '' : (trim((string) ($b['receptor_codigo_prestacion'] ?? '')) ?: trim((string) ($cli['codigo_prestacion'] ?? '')));
                    $dDirFull = trim($dDir . ($dCom !== '' ? ($dDir !== '' ? ', ' : '') . $dCom : ''));
                    ?>
                    <?php if ($dDirFull !== ''): ?><div style="font-size:12px;color:<?= $C['gris'] ?>;">Dirección: <?= e($dDirFull) ?></div><?php endif; ?>
                    <?php if ($dCod !== ''): ?><div style="font-size:12px;color:<?= $C['gris'] ?>;">Cód. prestación: <?= e($dCod) ?></div><?php endif; ?>
                    <?php if (!empty($b['receptor_email'])): ?><div style="font-size:12px;color:<?= $C['gris'] ?>;"><?= e($b['receptor_email']) ?></div><?php endif; ?>
                    <?php if ($esTutor && !empty($cli['nombre'])): ?><div style="font-size:12px;color:<?= $C['cafe'] ?>;margin-top:4px;"><strong>Paciente atendido:</strong> <?= e($cli['nombre']) ?><?= !empty($cli['rut']) ? ' · RUT ' . e($cli['rut']) : '' ?></div><?php endif; ?>

                    <?php if (!empty($b['profesional_nombre'])): ?>
                    <div style="margin-top:10px;padding-top:10px;border-top:1px solid <?= $C['linea'] ?>;">
                        <div style="font-size:10px;letter-spacing:.5px;text-transform:uppercase;color:<?= $C['gris'] ?>;font-weight:700;">Profesional</div>
                        <div style="font-size:14px;font-weight:700;margin-top:3px;"><?= e($b['profesional_nombre']) ?></div>
                        <?php if (!empty($b['profesional_rut'])): ?><div style="font-size:12px;color:<?= $C['gris'] ?>;">RUT: <?= e(formatoRut((string) $b['profesional_rut'])) ?></div><?php endif; ?>
                    </div>
                    <?php endif; ?>
                </td>
                <td style="padding:12px 16px;vertical-align:top;text-align:right;">
                    <div style="font-size:10px;letter-spacing:.5px;text-transform:uppercase;color:<?= $C['gris'] ?>;font-weight:700;">Medio de pago</div>
                    <div style="font-size:14px;font-weight:700;margin-top:3px;"><?= e(medioPagoLabel($b['medio_pago'])) ?></div>
                    <?php if (!empty($b['emisor_nombre'])): ?><div style="font-size:11px;color:<?= $C['gris'] ?>;margin-top:4px;">Emitida por <?= e($b['emisor_nombre']) ?></div><?php endif; ?>
                </td>
            </tr>
        </table>

        <!-- Detalle -->
        <table style="width:100%;border-collapse:collapse;margin-top:20px;">
            <thead><tr style="border-bottom:2px solid <?= $C['linea'] ?>;">
                <th style="text-align:left;padding:8px 6px;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:<?= $C['gris'] ?>;">Detalle</th>
                <th style="text-align:center;padding:8px 6px;font-size:11px;text-transform:uppercase;color:<?= $C['gris'] ?>;">Cant.</th>
                <th style="text-align:right;padding:8px 6px;font-size:11px;text-transform:uppercase;color:<?= $C['gris'] ?>;">P. neto</th>
                <th style="text-align:right;padding:8px 6px;font-size:11px;text-transform:uppercase;color:<?= $C['gris'] ?>;">Total</th>
            </tr></thead>
            <tbody>
                <?php foreach ($b['items'] as $it): ?>
                <tr style="border-bottom:1px solid <?= $C['linea'] ?>;">
                    <td style="padding:9px 6px;font-size:13px;"><?= e($it['descripcion']) ?><?= empty($it['afecto']) ? ' <span style="font-size:10px;color:'.$C['gris'].';">(exento)</span>' : '' ?></td>
                    <td style="padding:9px 6px;font-size:13px;text-align:center;"><?= (int) $it['cantidad'] ?></td>
                    <td style="padding:9px 6px;font-size:13px;text-align:right;"><?= e(clp((int) $it['precio_unit_clp'])) ?></td>
                    <td style="padding:9px 6px;font-size:13px;text-align:right;font-weight:700;"><?= e(clp((int) $it['total_clp'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Totales -->
        <table style="width:100%;border-collapse:collapse;margin-top:14px;"><tr>
            <td></td>
            <td style="width:240px;">
                <?php if ((int) $b['neto_clp'] > 0): ?>
                <table style="width:100%;font-size:13px;"><tr><td style="padding:3px 6px;color:<?= $C['gris'] ?>;">Neto</td><td style="padding:3px 6px;text-align:right;"><?= e(clp((int) $b['neto_clp'])) ?></td></tr>
                <tr><td style="padding:3px 6px;color:<?= $C['gris'] ?>;">IVA 19%</td><td style="padding:3px 6px;text-align:right;"><?= e(clp((int) $b['iva_clp'])) ?></td></tr></table>
                <?php endif; ?>
                <?php if ((int) $b['exento_clp'] > 0): ?>
                <table style="width:100%;font-size:13px;"><tr><td style="padding:3px 6px;color:<?= $C['gris'] ?>;">Exento</td><td style="padding:3px 6px;text-align:right;"><?= e(clp((int) $b['exento_clp'])) ?></td></tr></table>
                <?php endif; ?>
                <table style="width:100%;margin-top:6px;background:<?= $C['cafe'] ?>;border-radius:8px;">
                    <tr><td style="padding:10px 12px;color:#fff;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">Total</td>
                    <td style="padding:10px 12px;color:#fff;font-size:18px;font-weight:800;text-align:right;"><?= e(clp((int) $b['total_clp'])) ?></td></tr>
                </table>
            </td>
        </tr></table>

        <?php if (!empty($b['glosa'])): ?>
        <div style="margin-top:14px;font-size:12px;color:<?= $C['gris'] ?>;"><strong>Observaciones:</strong> <?= e($b['glosa']) ?></div>
        <?php endif; ?>

        <!-- Nota MVP / SII -->
        <div style="margin-top:22px;padding:10px 14px;background:#FBF3EC;border:1px dashed <?= $acc ?>;border-radius:8px;font-size:11px;color:<?= $C['cafe'] ?>;line-height:1.5;">
            <?php if (empty($em['rut_configurado'])): ?><strong>Configura el RUT del emisor</strong> en el módulo Empresas. <?php endif; ?>
            Documento interno emitido por <?= e($em['razon_social'] ?? $em['nombre']) ?>. <strong>Pendiente de timbrado electrónico ante el SII</strong> — se convertirá en <?= e(mb_strtolower($tituloDoc)) ?> válida al conectar la emisión con el SII.
        </div>
    </div>
</div>
<?php
        return ob_get_clean();
    }
}

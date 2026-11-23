<?php
declare(strict_types=1);

/**
 * Helpers de REPRESENTACIÓN impresa de DTE: Timbre Electrónico (TED),
 * leyenda de resolución SII y logo de la empresa emisora.
 *
 * Se usa en las vistas *_ver.php (factura, exenta, guía, NC, ND) para que
 * todas muestren el timbre y el logo de forma consistente y con URLs limpias
 * (sin .php), funcionando tanto en local como en producción.
 */

if (!function_exists('dte_doc_e')) {
    function dte_doc_e($v): string {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('dte_timbre_url')) {
    /** URL relativa (limpia) del endpoint que genera el PDF417 del timbre. */
    function dte_timbre_url(int $id, int $tipo): string {
        return 'generar_timbre?id=' . $id . '&tipo=' . $tipo;
    }
}

if (!function_exists('dte_estado_label')) {
    /** Normaliza cualquier estado a solo tres: Enviado / Rechazado / Anulado. */
    function dte_estado_label($e): string {
        $e = strtoupper(trim((string)$e));
        if (in_array($e, ['RECHAZADO','RECHAZADA','ERROR','FALLA'], true)) return 'Rechazado';
        if (in_array($e, ['ANULADO','ANULADA','NULA'], true))            return 'Anulado';
        return 'Enviado';
    }
}

if (!function_exists('dte_estado_badge')) {
    /** Píldora de estado (autoconstruida, sirve en cualquier pantalla). */
    function dte_estado_badge($e): string {
        $lbl = dte_estado_label($e);
        $col = ['Enviado' => ['#dcfce7','#166534'], 'Rechazado' => ['#fee2e2','#991b1b'], 'Anulado' => ['#e5e7eb','#334155']];
        [$bg, $fg] = $col[$lbl];
        return '<span style="display:inline-block;font-size:11px;font-weight:800;padding:3px 11px;border-radius:999px;background:' . $bg . ';color:' . $fg . '">' . $lbl . '</span>';
    }
}

if (!function_exists('dte_ncs_por_documento')) {
    /**
     * Mapa de documentos que tienen una Nota de Crédito emitida en su contra.
     * Clave "empresaId|tipoDoc|folio" → ['nc_folio'=>int, 'codigo'=>int].
     * codigo: 1=anula, 2=corrige texto, 3=corrige monto (se prioriza el 1).
     * Se consulta UNA vez por listado (no una query por fila).
     * @param int[] $empresas ids de empresa a considerar (vacío = todas)
     */
    function dte_ncs_por_documento(mysqli $conex, array $empresas = []): array {
        $out = [];
        $wEmp = '';
        if ($empresas) {
            $ids = implode(',', array_map('intval', $empresas));
            $wEmp = " AND nc.empresa_id IN ($ids)";
        }
        $sql = "SELECT nc.empresa_id, r.tipo_doc, r.folio, r.codigo, nc.folio AS nc_folio
                FROM dte_documento_ref r
                JOIN dte_documentos nc ON nc.id = r.documento_id
                WHERE nc.tipo_dte IN (61,112)
                  AND nc.estado NOT IN ('RECHAZADO','RECHAZADA','ERROR','FALLA')" . $wEmp;
        $res = @mysqli_query($conex, $sql);
        if (!$res) return $out;
        while ($x = mysqli_fetch_assoc($res)) {
            $k = (int)$x['empresa_id'] . '|' . (int)$x['tipo_doc'] . '|' . (int)$x['folio'];
            if (!isset($out[$k]) || (int)$x['codigo'] === 1) {
                $out[$k] = ['nc_folio' => (int)$x['nc_folio'], 'codigo' => (int)$x['codigo']];
            }
        }
        mysqli_free_result($res);
        return $out;
    }
}

if (!function_exists('dte_badge_nc')) {
    /** Píldora violeta para un documento con Nota de Crédito en su contra. */
    function dte_badge_nc(int $ncFolio, int $codigo): string {
        $txt = ($codigo === 1) ? 'Anulada por NC' : 'Con NC';
        return '<span style="display:inline-block;font-size:10px;font-weight:800;padding:2px 9px;border-radius:999px;'
             . 'background:#ede9fe;color:#6d28d9;white-space:nowrap" title="Nota de Crédito folio ' . $ncFolio . '">'
             . '<i class="bi bi-file-earmark-break"></i> ' . $txt . ' N° ' . $ncFolio . '</span>';
    }
}

if (!function_exists('dte_timbre_url_envio')) {
    /** URL relativa del timbre por id de sii_envios (flujo dte_documentos). */
    function dte_timbre_url_envio(int $envioId): string {
        return 'generar_timbre?envio_id=' . $envioId;
    }
}

if (!function_exists('dte_resolucion')) {
    /** Resolución SII activa (desde config/app.php) para la leyenda del timbre. */
    function dte_resolucion(): array {
        static $cache = null;
        if ($cache !== null) return $cache;

        $num = 0; $fecha = '';
        // EspacioLuz: la resolución vive en la config del módulo DTE (dte/config.php).
        $cfgFile = __DIR__ . '/../config.php';
        if (is_file($cfgFile)) {
            $cfg = include $cfgFile;
            if (is_array($cfg)) {
                $num   = (int)($cfg['numero_resolucion'] ?? 0);
                $fecha = (string)($cfg['fecha_resolucion'] ?? '');
            }
        }
        return $cache = ['numero' => $num, 'fecha' => $fecha];
    }
}

if (!function_exists('dte_timbre_block')) {
    /**
     * Bloque HTML del Timbre Electrónico (TED), listo para pantalla o impresión.
     * @param int $id    ID del documento en su tabla.
     * @param int $tipo  Tipo DTE (33,34,39,41,43,46,52,56,61,110,111,112).
     */
    function dte_timbre_block(int $id, int $tipo): string {
        $res = dte_resolucion();
        $url = dte_doc_e(dte_timbre_url($id, $tipo));

        $resLinea = '';
        if (!empty($res['numero'])) {
            $resLinea = 'Res. N° ' . (int)$res['numero'];
            if (!empty($res['fecha'])) {
                $ts = strtotime($res['fecha']);
                if ($ts) $resLinea .= ' de ' . date('d-m-Y', $ts);
            }
        }
        $resLinea = dte_doc_e($resLinea);
        $resHtml  = $resLinea !== '' ? "<div class=\"dte-timbre__res\">{$resLinea}</div>" : '';

        return <<<HTML
<div class="dte-timbre">
  <img class="dte-timbre__img" src="{$url}" alt="Timbre Electrónico SII (PDF417)" loading="lazy">
  <div class="dte-timbre__cap">Timbre Electrónico SII</div>
  {$resHtml}
  <div class="dte-timbre__verify">Verifique documento: www.sii.cl</div>
</div>
HTML;
    }
}

if (!function_exists('dte_timbre_block_envio')) {
    /** Igual que dte_timbre_block, pero por id de sii_envios. */
    function dte_timbre_block_envio(int $envioId): string {
        $res = dte_resolucion();
        $url = dte_doc_e(dte_timbre_url_envio($envioId));

        $resLinea = '';
        if (!empty($res['numero'])) {
            $resLinea = 'Res. N° ' . (int)$res['numero'];
            if (!empty($res['fecha'])) {
                $ts = strtotime($res['fecha']);
                if ($ts) $resLinea .= ' de ' . date('d-m-Y', $ts);
            }
        }
        $resLinea = dte_doc_e($resLinea);
        $resHtml  = $resLinea !== '' ? "<div class=\"dte-timbre__res\">{$resLinea}</div>" : '';

        return <<<HTML
<div class="dte-timbre">
  <img class="dte-timbre__img" src="{$url}" alt="Timbre Electrónico SII (PDF417)" loading="lazy">
  <div class="dte-timbre__cap">Timbre Electrónico SII</div>
  {$resHtml}
  <div class="dte-timbre__verify">Verifique documento: www.sii.cl</div>
</div>
HTML;
    }
}

if (!function_exists('dte_tipo_despacho_nombre')) {
    /** Glosa SII del Tipo de Despacho (guía 52). */
    function dte_tipo_despacho_nombre($c): string {
        $m = [1 => 'Por cuenta del receptor', 2 => 'Por cuenta del emisor a instalaciones del cliente', 3 => 'Por cuenta del emisor a otras instalaciones'];
        return $m[(int)$c] ?? (string)$c;
    }
}

if (!function_exists('dte_ind_traslado_nombre')) {
    /** Glosa SII del Indicador de Traslado (guía 52). */
    function dte_ind_traslado_nombre($c): string {
        $m = [1 => 'Constituye venta', 2 => 'Venta por efectuar', 3 => 'Consignación', 4 => 'Entrega gratuita',
              5 => 'Traslado interno', 6 => 'Otros traslados no venta', 7 => 'Guía de devolución',
              8 => 'Traslado para exportación', 9 => 'Venta para exportación'];
        return $m[(int)$c] ?? (string)$c;
    }
}

if (!function_exists('dte_ref_codigo_nombre')) {
    /** Glosa SII del código de referencia (NC/ND). */
    function dte_ref_codigo_nombre($c): string {
        $m = [1 => 'Anula documento', 2 => 'Corrige texto', 3 => 'Corrige montos'];
        return $m[(int)$c] ?? (string)$c;
    }
}

if (!function_exists('dte_ted_from_xml')) {
    /** Extrae el nodo <TED>...</TED> de un XML DTE. */
    function dte_ted_from_xml(string $xml): ?string {
        $xml = trim($xml);
        if ($xml === '') return null;
        if (preg_match('/<TED\b[^>]*>.*?<\/TED>/si', $xml, $m)) return $m[0];
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $ted = null;
        if ($dom->loadXML($xml, LIBXML_NOBLANKS | LIBXML_NOCDATA)) {
            $n = $dom->getElementsByTagName('TED');
            if ($n->length > 0) $ted = $dom->saveXML($n->item(0));
        }
        libxml_clear_errors();
        return $ted;
    }
}

if (!function_exists('dte_timbre_png_datauri')) {
    /** PNG del timbre PDF417 como data URI (para incrustar en PDF/HTML). '' si no hay TED. */
    function dte_timbre_png_datauri(string $xmlDte): string {
        $ted = dte_ted_from_xml($xmlDte);
        if (!$ted || !class_exists('TCPDF2DBarcode')) return '';
        try {
            $bc  = new TCPDF2DBarcode($ted, 'PDF417');
            $png = $bc->getBarcodePngData(3, 1.5, [0, 0, 0]);
            if (empty($png)) return '';
            return 'data:image/png;base64,' . base64_encode($png);
        } catch (Throwable $e) {
            return '';
        }
    }
}

if (!function_exists('dte_logo_datauri')) {
    /** Logo de la empresa como data URI (para incrustar en PDF). '' si no existe. */
    function dte_logo_datauri(?string $rel): string {
        $l = dte_logo($rel);
        if (!$l['ok']) return '';
        $base = defined('APP_PATH') ? APP_PATH : (__DIR__ . '/..');
        $path = $base . '/' . $l['url'];
        if (!is_file($path)) return '';
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = $ext === 'png' ? 'image/png' : ($ext === 'webp' ? 'image/webp' : 'image/jpeg');
        $data = @file_get_contents($path);
        if ($data === false) return '';
        return 'data:' . $mime . ';base64,' . base64_encode($data);
    }
}

if (!function_exists('dte_logo')) {
    /**
     * Resuelve la URL relativa y la existencia del logo de una empresa.
     * Acepta rutas tipo "assets/uploads/logos_empresas/x.png" o "x.png".
     * @return array{url:string, ok:bool}
     */
    function dte_logo(?string $rel): array {
        $rel = trim((string)$rel);
        if ($rel === '') return ['url' => '', 'ok' => false];

        $base = defined('APP_PATH') ? APP_PATH : (__DIR__ . '/..');
        $cands = [ltrim($rel, '/'), 'assets/img/' . ltrim($rel, '/')];
        foreach ($cands as $c) {
            if (is_file($base . '/' . $c)) {
                return ['url' => $c, 'ok' => true];
            }
        }
        return ['url' => ltrim($rel, '/'), 'ok' => false];
    }
}

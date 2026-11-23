<?php
declare(strict_types=1);

/**
 * TRIVOXA ERP — Emisor unitario unificado (PRODUCCIÓN, multi-empresa)
 * Copia sincronizada con Trivoxa-DTE (motor certificado ante el SII) el 2026-11-16,
 * con las adaptaciones de EspacioLuz: el receptor opcional se toma de `pacientes`
 * y dte_documentos usa la columna paciente_id.
 * ------------------------------------------------------------------
 * Una sola función emite CUALQUIER tipo de DTE de forma unitaria (sin lote):
 *   genera el DTE timbrado -> genera el sobre EnvioDTE -> lo envía al SII
 *   (palena) -> registra y consume el folio.
 *
 * Calcado al emisor de factura que ya pasó certificación. Reutiliza el
 * TrivoxaApiClient y las credenciales/CAF por empresa (lib/dte_cred.php).
 *
 * Tipos soportados:
 *   33 Factura · 34 Factura exenta · 39 Boleta · 41 Boleta exenta
 *   43 Liquidación-factura · 46 Factura de compra
 *   52 Guía de despacho (con traslado) · 56 Nota de débito · 61 Nota de crédito
 *   110 Factura exportación · 111 NC exportación · 112 ND exportación
 */

require_once __DIR__ . '/dte_cred.php';

function dte_str($v): string { return trim((string)($v ?? '')); }
function dte_int($v): int { return (int)($v ?? 0); }
function dte_float($v): float { return (float)str_replace(',', '.', (string)($v ?? '0')); }
function dte_norm_rut(string $r): string { return strtoupper(str_replace(['.', ' ', "\t", "\r", "\n"], '', trim($r))); }

/** Tipos que NO llevan IVA / son exentos o moneda extranjera */
function dte_es_exportacion(int $t): bool { return in_array($t, [110, 111, 112], true); }
function dte_es_exento_total(int $t): bool { return in_array($t, [34, 41], true); }
function dte_lleva_referencia(int $t): bool { return in_array($t, [56, 61, 111, 112], true); }

/** Extrae el TrackID de la respuesta de enviarEnvio (puede venir como número o JSON). */
function dte_extraer_trackid($body): ?string {
    if ($body === null) return null;
    if (is_numeric($body)) return (string)$body;
    if (is_string($body)) {
        $j = json_decode($body, true);
        if (is_array($j)) {
            foreach (['trackid', 'trackId', 'TrackId', 'TRACKID'] as $k) if (isset($j[$k])) return (string)$j[$k];
        }
        if (preg_match('/\b(\d{6,})\b/', $body, $m)) return $m[1];
        return trim($body) !== '' ? trim($body) : null;
    }
    if (is_array($body)) {
        foreach (['trackid', 'trackId', 'TrackId'] as $k) if (isset($body[$k])) return (string)$body[$k];
    }
    return null;
}

/** Convierte la respuesta de consultarEnvio a texto plano para inspeccionarla. */
function dte_estado_texto($body): string {
    if (is_array($body)) return (string)json_encode($body, JSON_UNESCAPED_UNICODE);
    return (string)$body;
}

/** Extrae una glosa legible del cuerpo devuelto por el SII/Trivoxa API DTE. */
function dte_glosa_estado_sii(string $txt): string {
    $j = json_decode($txt, true);
    if (is_array($j)) {
        foreach (['detalle', 'glosa', 'glosaEstado', 'descripcion', 'error', 'estado', 'mensaje'] as $k) {
            if (!empty($j[$k]) && is_string($j[$k])) return trim($j[$k]);
        }
    }
    return trim(mb_substr($txt, 0, 500));
}

/**
 * Consulta el estado REAL de un envío en el SII a partir del TrackID.
 * enviarEnvio() sólo confirma que el SOBRE fue recibido; la validación de
 * schema/firma es posterior y asíncrona. Devuelve:
 *   ['estado' => 'ENVIADO'|'RECHAZADO'|'PENDIENTE', 'glosa' => string|null, 'raw' => mixed]
 *   - ENVIADO   = el SII aceptó/procesó el envío (EPR/DOK).
 *   - RECHAZADO = el SII lo rechazó (schema RSC, firma RFR, carátula, etc.).
 *   - PENDIENTE = aún en proceso; no se puede afirmar aceptación ni rechazo.
 */
function dte_consultar_estado_sii(TrivoxaApiClient $cli, array $cfg, string $trackid, int $tipo = 0, int $intentos = 3, ?array $pausas = null): array {
    // $pausas: segundos de espera entre consultas (consulta 1, pausa[0], consulta 2, …).
    // Si se indica, define también el número de consultas (count + 1).
    if ($pausas !== null) $intentos = count($pausas) + 1;
    $dormir = function (int $i) use ($pausas): void { usleep((int) round((float) ($pausas[$i] ?? 1.8) * 1000000)); };
    $trackid = trim($trackid);
    if ($trackid === '' || !ctype_digit($trackid)) {
        return ['estado' => 'PENDIENTE', 'glosa' => 'Sin TrackID válido', 'raw' => null];
    }

    // El servidor REST de boletas del SII EXIGE el RUT del emisor partido en cuerpo y
    // dígito verificador para consultar el estado por TrackID; sin ellos, Trivoxa API DTE
    // responde HTTP 500 en /Consulta/envio (las boletas quedaban "en proceso" para
    // siempre). Se toma del emisor (empresa) resuelto en dte_resolver_cfg.
    $emisorRut = dte_norm_rut((string)($cfg['emisor_rut'] ?? $cfg['cert_rut'] ?? ''));
    $rutPz     = explode('-', $emisorRut);
    $payload = [
        'certificado' => [
            'ruta'     => (string)($cfg['cert_path'] ?? ''),
            'password' => (string)($cfg['cert_pass'] ?? ''),
            'rut'      => (string)($cfg['cert_rut'] ?? ''),
        ],
        // El servidor REST de boletas del SII exige la AUTENTICACIÓN (token, con el
        // titular del certificado). Sin ella /Consulta/envio devolvía HTTP 500 y las
        // boletas quedaban "en proceso" para siempre.
        'autenticacion' => [
            'rut'      => (string)($cfg['cert_rut'] ?? ''),
            'password' => (string)($cfg['cert_pass'] ?? ''),
        ],
        'rutEmpresaCuerpo'   => (string)($rutPz[0] ?? ''),
        'rutEmpresaDV'       => (string)($rutPz[1] ?? ''),
        'trackId'            => (string)$trackid,
        // El servidor REST de boletas del SII EXIGE ademas el RUT del emisor COMPLETO
        // (cuerpo-DV). Sin 'rutEmpresa' completo, Trivoxa API DTE responde HTTP 500 con cuerpo
        // vacio en /Consulta/envio (verificado 2026-09-24: solo con este campo da 200).
        'rutEmpresa'         => $emisorRut,
        // servidorBoletaREST solo para boletas (39/41); las facturas usan el servidor normal.
        'servidorBoletaREST' => in_array($tipo, [39, 41], true),
        'ambiente'           => (string)($cfg['ambiente'] ?? 'produccion'),
    ];

    $raw = null;
    for ($i = 0; $i < max(1, $intentos); $i++) {
        $r   = $cli->consultarEnvio($payload);
        $raw = $r;
        $txt = dte_estado_texto($r['body'] ?? '');
        $up  = mb_strtoupper($txt, 'UTF-8');

        // --- Veredicto ESTRUCTURADO por CONTADORES del SII (TODOS los tipos) ---
        // Las respuestas del SII/Trivoxa API DTE traen SIEMPRE la palabra "rechazados" como
        // CONTADOR (boletas REST: "estadistica"[].rechazados; facturas y resto:
        // "estados"[].cantidadRechazados / <RECHAZADOS>0). Por eso la heurística de
        // texto (str_contains 'RECHAZ') daba FALSO RECHAZO cuando el SII en realidad
        // ACEPTÓ (estado EPR, aceptados>=1, rechazados=0). Se interpreta por los
        // contadores reales + el código de estado del envío, no por el texto crudo.
        $j = json_decode($txt, true);
        if (is_array($j)) {
            $estadoCod = mb_strtoupper(trim((string)($j['estado'] ?? '')), 'UTF-8');
            $errores   = trim((string)($j['errores'] ?? ''));
            $ace = 0; $rej = 0; $rep = 0; $hayCont = false;
            // Boletas REST usan 'estadistica' (aceptados/…); el resto 'estados'
            // (cantidadAceptados/…). Se suman ambas variantes de claves.
            foreach (['estadistica', 'estados'] as $kk) {
                if (!empty($j[$kk]) && is_array($j[$kk])) {
                    foreach ($j[$kk] as $e) {
                        if (!is_array($e)) continue;
                        $ace += (int)($e['aceptados']  ?? $e['cantidadAceptados']  ?? 0);
                        $rej += (int)($e['rechazados'] ?? $e['cantidadRechazados'] ?? 0);
                        $rep += (int)($e['reparos']    ?? $e['cantidadReparos']    ?? 0);
                        $hayCont = true;
                    }
                }
            }
            if ($hayCont || $estadoCod !== '') {
                // Rechazo REAL: DTE rechazados, errores no vacíos, o código de envío que
                // empieza por R (RCH/RSC/RFR/RLV/…), salvo REC (Recibido).
                if ($rej > 0 || $errores !== '' || (str_starts_with($estadoCod, 'R') && $estadoCod !== 'REC')) {
                    $g = $errores !== '' ? $errores : ('Estado SII ' . ($estadoCod ?: '?') . " (rechazados={$rej})");
                    return ['estado' => 'RECHAZADO', 'glosa' => $g, 'raw' => $r];
                }
                // Aceptado: envío procesado (EPR/DOK) con >=1 DTE aceptado y sin rechazos.
                // (reparos>0 = aceptado con observaciones; también se considera ENVIADO.)
                if (in_array($estadoCod, ['EPR', 'DOK'], true) && $ace >= 1) {
                    return ['estado' => 'ENVIADO', 'glosa' => null, 'raw' => $r];
                }
                // Estados intermedios (REC/SOK/CRT/PDR/-11/en proceso): validándose -> reintentar.
                if ($i < $intentos - 1) { $dormir($i); continue; }
                continue;
            }
        }

        // --- Fallback: heurística de texto (SOLO si no vino JSON estructurado) ---
        // Códigos de estado de envío que empiezan por R = rechazado
        // (RCH, RCT, RSC=schema, RFR=firma, RDC, RCO, RPR, RLV, etc.). "REC" se excluye.
        // NO se usa str_contains('RECHAZ'): hacía falso match con el contador "rechazados".
        if (preg_match('/"ESTADO"\s*:\s*"R(?!EC")[A-Z]{2}"/', $up)
            || preg_match('/\bR(CH|CT|SC|FR|DC|CO|PR|LV)\b/', $up)
            || str_contains($up, 'ERROR EN SCHEMA')
            || str_contains($up, 'ERROR DE SCHEMA')) {
            return ['estado' => 'RECHAZADO', 'glosa' => dte_glosa_estado_sii($txt), 'raw' => $r];
        }

        // --- Aceptación / procesado final ---
        if (preg_match('/\b(EPR|DOK)\b/', $up)
            || str_contains($up, 'ACEPTAD')
            || str_contains($up, 'PROCESADO')) {
            return ['estado' => 'ENVIADO', 'glosa' => null, 'raw' => $r];
        }

        // --- Aún en proceso (REC/SOK/CRT/FOK/PDR/En Proceso): reintentar ---
        if ($i < $intentos - 1) $dormir($i); // pausa entre intentos (1,8 s por defecto)
    }

    return ['estado' => 'PENDIENTE', 'glosa' => 'En proceso de validación en el SII', 'raw' => $raw];
}

/** Registra cuánto tardó cada etapa de una emisión (log privado, sin datos sensibles). */
function dte_log_tiempos(array $cfgBase, int $tipo, int $folio, array $tm, string $estado): void {
    $dir = (string)($cfgBase['debug_log_dir'] ?? '');
    if ($dir === '' || !is_dir($dir) || !is_writable($dir) || empty($tm['inicio'])) return;
    $prev = $tm['inicio']; $partes = [];
    foreach (['generar' => 'generar', 'sobre' => 'sobre', 'envio' => 'envío SII', 'veredicto' => 'veredicto', 'registro' => 'registro'] as $k => $l) {
        if (!isset($tm[$k])) continue;
        $partes[] = $l . ' ' . number_format($tm[$k] - $prev, 2, ',', '') . 's';
        $prev = $tm[$k];
    }
    $line = '[' . date('Y-m-d H:i:s') . "] tipo {$tipo} folio {$folio} · " . implode(' · ', $partes)
          . ' · TOTAL ' . number_format($prev - $tm['inicio'], 2, ',', '') . "s · {$estado}\n";
    @file_put_contents($dir . '/tiempos-' . date('Ym') . '.log', $line, FILE_APPEND);
}

/**
 * Emite un DTE. $p:
 *   empresa_id (int, requerido)
 *   tipo (int, requerido)
 *   receptor: [rut, razon_social, giro, direccion, comuna, ciudad, contacto, correo, nacionalidad?]
 *   items: [{descripcion, cantidad, unidad, precio_unitario, descuento_porcentaje?, exento?, codigo?}]
 *   referencias: [{tipo_doc, folio, fecha, codigo, razon}]   (para NC/ND/export)
 *   transporte: [tipo_despacho, ind_traslado, patente?, rut_transportista?, chofer?, dir_destino?, comuna_destino?]  (guía 52)
 *   aduana: [moneda, tipo_cambio, ...]  (export 110/111/112)
 *   fecha_emision, fecha_vencimiento, forma_pago(1=contado,2=crédito), condicion_pago, descuento_global_pct, observaciones
 *
 * Devuelve array con ok/trackid/folio/total/estado o error.
 */
function dte_emitir(mysqli $conex, array $cfgBase, array $p): array {
    $empresaId = dte_int($p['empresa_id'] ?? 0);
    $tipo      = dte_int($p['tipo'] ?? 0);
    if ($empresaId <= 0 || $tipo <= 0) return ['ok' => false, 'error' => 'Falta empresa_id o tipo'];
    if ($cfgBase['ambiente'] !== 'produccion') return ['ok' => false, 'error' => 'config/produccion.php debe tener ambiente=produccion'];

    $items = $p['items'] ?? [];
    if (!is_array($items) || !count($items)) return ['ok' => false, 'error' => 'No hay items'];

    // Empresa emisora
    $st = $conex->prepare("SELECT id, rut, razon_social, giro, direccion, comuna, ciudad FROM empresas WHERE id=? LIMIT 1");
    $st->bind_param('i', $empresaId); $st->execute();
    $empresa = $st->get_result()->fetch_assoc(); $st->close();
    if (!$empresa) return ['ok' => false, 'error' => 'Empresa no existe'];

    // Credenciales + CAF + folio
    try {
        [$cfg, $caf] = dte_resolver_cfg($conex, $cfgBase, $empresaId, $tipo);
    } catch (Throwable $e) { return ['ok' => false, 'error' => $e->getMessage()]; }

    // Folio: RESERVA ATÓMICA para evitar que dos emisiones concurrentes tomen el
    // mismo folio (carrera) y lo envíen dos veces al SII. Si el usuario pasa un
    // folio explícito (reintento de un folio antes rechazado), se respeta sin reservar.
    $folio = dte_int($p['folio'] ?? 0);
    $folioReservado = false;
    if ($folio <= 0) {
        $folio = dte_reservar_folio($conex, (int)$caf['id']);
        if ($folio === null) {
            return ['ok' => false, 'error' => "Sin folios disponibles para el tipo {$tipo}. Carga un nuevo CAF."];
        }
        $folioReservado = true;
    } elseif ($folio > (int)$caf['folio_hasta']) {
        return ['ok' => false, 'error' => "Sin folios disponibles para el tipo {$tipo} (folio {$folio} > {$caf['folio_hasta']}). Carga un nuevo CAF."];
    } elseif ($folio < (int)$caf['folio_actual']) {
        // SEGURIDAD DE FOLIOS: un folio explícito por debajo del correlativo actual
        // ya fue consumido. Reenviarlo duplicaría el folio ante el SII o haría
        // retroceder el correlativo (reutilización de folios válidos). Se rechaza.
        return ['ok' => false, 'error' => "El folio {$folio} ya fue consumido (correlativo actual del CAF: {$caf['folio_actual']}). No se puede reutilizar un folio anterior; emite sin folio explícito para tomar el siguiente disponible."];
    }

    $cli = new TrivoxaApiClient($cfg);

    // ---------- Detalle y totales (calcado al emisor de factura) ----------
    $exportacion = dte_es_exportacion($tipo);
    $exentoTotal = dte_es_exento_total($tipo);
    $detalle = []; $afectoBruto = 0; $exento = 0;

    foreach ($items as $it) {
        $desc = dte_str($it['descripcion'] ?? '');
        $cant = dte_float($it['cantidad'] ?? 0);
        $prec = dte_float($it['precio_unitario'] ?? $it['precio'] ?? 0);
        $uni  = dte_str($it['unidad'] ?? 'UN');
        $cod  = dte_str($it['codigo'] ?? '');
        $dpct = dte_float($it['descuento_porcentaje'] ?? 0);
        $exe  = dte_int($it['exento'] ?? 0);
        if ($desc === '' || $cant <= 0) return ['ok' => false, 'error' => 'Item inválido (descripción/cantidad)'];
        if ($dpct < 0) $dpct = 0; if ($dpct > 100) $dpct = 100;
        if ($exportacion || $exentoTotal) $exe = 1;   // export y exenta: todo exento de IVA

        $bruto = $cant * $prec;
        $descItem = (int)round($bruto * ($dpct / 100));
        $linea = (int)round($bruto - $descItem);
        if ($exe === 1) $exento += $linea; else $afectoBruto += $linea;

        $detalle[] = ['codigo' => $cod, 'descripcion' => $desc, 'cantidad' => $cant, 'unidad' => $uni,
            'precio' => $prec, 'descuento' => $descItem, 'linea' => $linea, 'exento' => $exe,
            'producto_id' => dte_int($it['producto_id'] ?? 0)];
    }

    // Descuento global sobre afectos (prorrateado)
    $dgPct = dte_float($p['descuento_global_pct'] ?? 0);
    if ($dgPct < 0) $dgPct = 0; if ($dgPct > 100) $dgPct = 100;
    $dgMonto = (int)round($afectoBruto * ($dgPct / 100));
    if ($dgMonto > $afectoBruto) $dgMonto = $afectoBruto;
    $afectos = array_values(array_filter(array_keys($detalle), fn($i) => $detalle[$i]['exento'] !== 1));
    $rep = 0; $ultimo = $afectos ? end($afectos) : null;
    foreach ($detalle as $i => &$d) {
        $dg = 0;
        if ($d['exento'] !== 1 && $dgMonto > 0 && $afectoBruto > 0) {
            $dg = ($i === $ultimo) ? ($dgMonto - $rep) : (int)round($dgMonto * ($d['linea'] / $afectoBruto));
            if ($i !== $ultimo) $rep += $dg;
        }
        if ($dg < 0) $dg = 0; if ($dg > $d['linea']) $dg = $d['linea'];
        $d['descuento_total'] = $d['descuento'] + $dg;
        $d['monto_final'] = $d['linea'] - $dg;
    }
    unset($d);

    $neto = 0; foreach ($detalle as $d) if ($d['exento'] !== 1) $neto += $d['monto_final'];
    $iva  = $exportacion ? 0 : (int)round($neto * 0.19);
    $total = $neto + $exento + $iva;

    $esBoleta = in_array($tipo, [39, 41], true);
    $totales = $exportacion
        ? ['MontoExento' => $exento + $neto, 'MontoTotal' => $total, 'Moneda' => dte_str($p['aduana']['moneda'] ?? 'USD')]
        : ['MontoNeto' => $neto, 'TasaIVA' => 19, 'IVA' => $iva, 'MontoTotal' => $total];
    if (!$exportacion && $exento > 0) $totales['MontoExento'] = $exento;
    // BOLETA electrónica (39/41): el nodo <Totales> del EnvioBOLETA_v11.xsd NO admite
    // TasaIVA (a diferencia de la factura). Enviarla genera rechazo del SII
    // "LSX-00204: extra data at end of complex element". Se omite en boletas.
    if ($esBoleta) {
        unset($totales['TasaIVA']);
        // Boleta exenta (41 = exento total): sólo montos exentos, sin MntNeto/IVA en cero.
        if ($exentoTotal) $totales = ['MontoExento' => $exento, 'MontoTotal' => $total];
    }

    // ---------- IdentificacionDTE ----------
    $fEmis = dte_str($p['fecha_emision'] ?? date('Y-m-d'));
    $fVenc = dte_str($p['fecha_vencimiento'] ?? $fEmis);   // usado también en el registro en BD
    $idDoc = [
        'TipoDTE' => $tipo, 'Folio' => $folio, 'FechaEmision' => $fEmis,
    ];
    // BOLETA electrónica (39/41): el <IdDoc> del EnvioBOLETA_v11.xsd NO admite FmaPago
    // (que sí llevan factura/nota/guía). Enviarla provoca el rechazo del SII
    // "LSX-00204: extra data at end of complex element" en IdDoc. FchVenc es opcional
    // en boleta y no aporta (pago contado), así que también se omite del payload.
    if (!$esBoleta) {
        $idDoc['FechaVencimiento'] = $fVenc;
        $idDoc['FormaPago'] = dte_int($p['forma_pago'] ?? 1) ?: 1;
    }
    if ($tipo === 52) { // guía de despacho: tipo de traslado obligatorio
        $tr = $p['transporte'] ?? [];
        $idDoc['TipoDespacho'] = dte_int($tr['tipo_despacho'] ?? 2);  // 1=por emisor al cliente, 2=por emisor otro, 3=por receptor
        $idDoc['IndTraslado']  = dte_int($tr['ind_traslado'] ?? 1);   // 1=venta, 5=traslado interno, etc.
    }
    if (in_array($tipo, [39, 41], true)) {
        // Boletas electrónicas (39 afecta / 41 exenta): los montos se declaran NETOS
        // porque el sistema calcula desde precios netos; así la boleta desglosa
        // Neto + IVA (39) o solo Exento (41), coherente con contabilidad y Libro IVA.
        // Trivoxa API DTE auto-detecta la boleta y la envía al servidor REST de boletas del SII.
        // IndMntNeto: el XSD oficial del SII (EnvioBOLETA_v11.xsd) SOLO admite la
        // enumeracion 2 = "montos de las lineas de detalle son NETOS". El valor 1 no
        // existe en el schema y el SII rechaza con RSC/LSX-00290 (invalid enumeration
        // choice "1"). La clave del modelo JSON de Trivoxa API DTE es IndicadorMontosNetosBoleta
        // y se mapea a <IndMntNeto>. Se declara 2 (coherente: el sistema calcula desde neto).
        $idDoc['IndicadorMontosNetosBoleta'] = 2;
        // IndServicio es OBLIGATORIO en el Encabezado de la BOLETA electronica
        // (schema del SII, servidor REST de boletas): sin el, el SII rechaza con
        // RSC/LSX-00213 (only 0 occurrences of particle IndServicio). Valor 3 =
        // Boleta de Ventas y Servicios (venta de bienes y/o servicios). La clave
        // del modelo JSON de Trivoxa API DTE es 'IndicadorServicio' y se mapea a
        // <IndServicio> en el XML timbrado (binding case-insensitive descriptivo).
        $idDoc['IndicadorServicio'] = 3;
    }

    // ---------- Receptor ----------
    $rec = $p['receptor'] ?? [];
    // Si no viene el receptor pero sí un paciente_id, se trae de la tabla pacientes
    $cliId = dte_int($p['paciente_id'] ?? 0);
    if ($cliId > 0 && empty($rec['rut'])) {
        // EspacioLuz: la tabla pacientes usa `nombre` (no razon_social) y no tiene giro/contacto.
        $stc = $conex->prepare("SELECT rut, nombre AS razon_social, '' AS giro, direccion, comuna, '' AS contacto FROM pacientes WHERE id=? LIMIT 1");
        $stc->bind_param('i', $cliId); $stc->execute();
        $cl = $stc->get_result()->fetch_assoc(); $stc->close();
        if ($cl) $rec = [
            'rut' => $cl['rut'], 'razon_social' => $cl['razon_social'], 'giro' => $cl['giro'],
            'direccion' => $cl['direccion'], 'comuna' => $cl['comuna'], 'contacto' => $cl['contacto'],
        ];
    }
    $receptor = [
        'Rut'         => dte_norm_rut(dte_str($rec['rut'] ?? '')),
        'RazonSocial' => dte_str($rec['razon_social'] ?? ''),
        'Direccion'   => dte_str($rec['direccion'] ?? ''),
        'Comuna'      => dte_str($rec['comuna'] ?? ''),
        'Giro'        => dte_str($rec['giro'] ?? ''),
        'Contacto'    => dte_str($rec['contacto'] ?? ''),
    ];
    // Boletas (39/41): si no se identifica receptor, se emite a "Consumidor Final".
    if (in_array($tipo, [39, 41], true) && $receptor['Rut'] === '') {
        $receptor['Rut'] = '66666666-6';
        $receptor['RazonSocial'] = $receptor['RazonSocial'] ?: 'Consumidor Final';
    }
    // BOLETA electrónica (39/41): el <Receptor> del EnvioBOLETA_v11.xsd NO admite
    // GiroRecep (que SÍ lleva la factura en DTE_v10). Trivoxa API DTE mapea la clave 'Giro'
    // del receptor a <GiroRecep>; al emitir una boleta a un receptor REAL con giro, el
    // SII rechaza con RSC/LSX-00204 "extra data at end of complex element" (elemento
    // GiroRecep no esperado en la secuencia del Receptor de boleta). El resto de claves
    // (RazonSocial->RznSocRecep, Direccion->DirRecep, Comuna->CmnaRecep, Contacto->Contacto)
    // SÍ están permitidas por el schema de boleta, así que sólo se omite 'Giro'. Las
    // facturas y demás DTE conservan el Giro del receptor.
    if (in_array($tipo, [39, 41], true)) {
        unset($receptor['Giro']);
    }

    // ---------- Transporte (guía) ----------
    $transporte = null;
    if ($tipo === 52) {
        $tr = $p['transporte'] ?? [];
        $transporte = [
            'Patente'         => dte_str($tr['patente'] ?? ''),
            'RutTransportista'=> dte_norm_rut(dte_str($tr['rut_transportista'] ?? '')),
            'DireccionDestino'=> dte_str($tr['dir_destino'] ?? ''),
            'ComunaDestino'   => dte_str($tr['comuna_destino'] ?? ''),
        ];
        // Normalización defensiva: códigos de traslado válidos y destino con valor.
        // El SII rechaza una guía con TipoDespacho/IndTraslado fuera de rango o sin
        // dirección de destino. Se clampa a un default sensato y se cae a la
        // dirección del receptor si el destino viene vacío (evita rechazo por schema).
        if (!in_array((int)$idDoc['TipoDespacho'], [1, 2, 3], true)) $idDoc['TipoDespacho'] = 2;
        if ((int)$idDoc['IndTraslado'] < 1 || (int)$idDoc['IndTraslado'] > 9) $idDoc['IndTraslado'] = 1;
        if ($transporte['DireccionDestino'] === '') $transporte['DireccionDestino'] = $receptor['Direccion'];
        if ($transporte['ComunaDestino'] === '')    $transporte['ComunaDestino']    = $receptor['Comuna'];
        if ($transporte['DireccionDestino'] === '') {
            return ['ok' => false, 'error' => 'Guía de despacho: indica la dirección de destino del traslado (o la dirección del receptor).'];
        }
    }

    // ---------- Encabezado / Detalles ----------
    // BOLETA electrónica (39/41): el <Emisor> del EnvioBOLETA_v11.xsd usa RznSocEmisor
    // y GiroEmisor (NO RznSoc/GiroEmis de la factura) y NO admite Acteco ni Telefono
    // en el Emisor. Trivoxa API DTE mapea razonSocialBoleta -> <RznSocEmisor> y
    // giroBoleta -> <GiroEmisor>; enviar razonSocial/giro o actividadEconomica produce
    // RznSoc/GiroEmis/Acteco y el SII rechaza con "LSX-00204: extra data at end of
    // complex element". Las facturas y demás DTE siguen usando el Emisor completo.
    if ($esBoleta) {
        $emisor = [
            'Rut'               => dte_norm_rut((string)$empresa['rut']),
            'RazonSocialBoleta' => (string)$empresa['razon_social'],
            'GiroBoleta'        => (string)$empresa['giro'],
            'DireccionOrigen'   => (string)$empresa['direccion'],
            'ComunaOrigen'      => (string)$empresa['comuna'],
            'CiudadOrigen'      => (string)$empresa['ciudad'],
        ];
    } else {
        $emisor = [
            'Rut' => dte_norm_rut((string)$empresa['rut']), 'RazonSocial' => (string)$empresa['razon_social'],
            'Giro' => (string)$empresa['giro'], 'ActividadEconomica' => [dte_int($cfgBase['acteco'] ?? 620200)],
            'DireccionOrigen' => (string)$empresa['direccion'], 'ComunaOrigen' => (string)$empresa['comuna'],
            'CiudadOrigen' => (string)$empresa['ciudad'], 'Telefono' => [],
        ];
    }
    $encabezado = [
        'IdentificacionDTE' => $idDoc,
        'Emisor' => $emisor,
        'Receptor' => $receptor,
        'RutSolicitante' => '',
        'Transporte' => $transporte,
        'Totales' => $totales,
    ];
    // Exportación (110/111/112): bloque Aduana con moneda y tipo de cambio.
    if ($exportacion) {
        $ad = $p['aduana'] ?? [];
        $adMoneda = dte_str($ad['moneda'] ?? 'USD');
        $adTC     = dte_float($ad['tipo_cambio'] ?? 0);
        // Validación previa (evita un rechazo críptico del SII por Aduana incompleta).
        if ($adMoneda === '') {
            return ['ok' => false, 'error' => 'Exportación: indica la moneda (ej. USD, EUR).'];
        }
        if ($adTC <= 0) {
            return ['ok' => false, 'error' => 'Exportación: indica un tipo de cambio válido (mayor que 0) a pesos.'];
        }
        $encabezado['Aduana'] = [
            'Moneda'      => $adMoneda,
            'TipoCambio'  => $adTC,
            'FormaPagoExp'=> dte_str($ad['forma_pago_exp'] ?? ''),
            'PaisDestino' => dte_str($ad['pais_destino'] ?? ''),
            'ViaTransporte' => dte_str($ad['via_transporte'] ?? ''),
        ];
    }

    $detalles = array_map(function ($d) {
        return [
            'IndicadorExento' => (int)$d['exento'], 'Nombre' => (string)$d['descripcion'],
            'Descripcion' => (string)$d['descripcion'], 'Cantidad' => (float)$d['cantidad'],
            'UnidadMedida' => (string)$d['unidad'], 'Precio' => (float)$d['precio'],
            'Recargo' => 0, 'Descuento' => (int)$d['descuento_total'], 'DescuentoMonto' => (int)$d['descuento_total'],
            'MontoItem' => (int)$d['monto_final'],
        ];
    }, $detalle);

    // ---------- Referencias (NC/ND/export) ----------
    // CRÍTICO: Trivoxa API DTE espera los nombres de campo de SU MODELO JSON, NO los del
    // XSD del SII. Nombres correctos (modelo JSON de referencias que acepta
    // Trivoxa API DTE):
    //   numero (int) · tipoDocumento (str) · folioReferencia (str) ·
    //   fechaDocumentoReferenciaString (str, AAAA-MM-DD) · codigoReferencia (str) ·
    //   razonReferencia (str)
    // Con otros nombres (Folio, FechaReferencia, TpoDocRef, FolioRef, etc.) Trivoxa API DTE
    // NO los mapea y genera una <Referencia> vacía -> el SII rechaza por schema
    // ("LSX-00213: 0 occurrences of FolioRef" o "LSX-00009: data missing").
    // El binding de Trivoxa API DTE es case-insensitive (el Detalle funciona en PascalCase).
    $referencias = [];
    $nLin = 0;
    foreach (($p['referencias'] ?? []) as $r) {
        $tpoRef = dte_str($r['tipo_doc'] ?? '');
        $folioR = dte_str($r['folio'] ?? '');
        // Fecha de referencia: debe ser una fecha válida AAAA-MM-DD. Si viene vacía o
        // mal formada, se cae a la fecha de emisión (un valor vacío rechaza el schema).
        $fchRef = dte_str($r['fecha'] ?? '');
        if ($fchRef !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fchRef)) {
            $ts = strtotime($fchRef);
            $fchRef = $ts ? date('Y-m-d', $ts) : '';
        }
        if ($fchRef === '') $fchRef = $fEmis;
        $codRef = dte_int($r['codigo'] ?? 0);   // 1=anula, 2=corrige texto, 3=corrige monto
        $razonR = dte_str($r['razon'] ?? '');
        // Una referencia sin tipo o sin folio es inválida ante el SII: se descarta.
        if ($tpoRef === '' || (int)$folioR <= 0) continue;
        $nLin++;
        // El código de referencia (1=anula, 2=corrige texto, 3=corrige montos) es
        // OBLIGATORIO y con semántica tributaria para NC/ND (56/61). Antes se asumía
        // 1 (anula) si faltaba, lo que convertía silenciosamente una ND de corrección
        // en una anulación. Ahora se exige explícito para NC/ND; para otros tipos
        // (referencias de exportación) el código va omitido.
        if (dte_lleva_referencia($tipo)) {
            if (!in_array($codRef, [1, 2, 3], true)) {
                return ['ok' => false, 'error' => "El tipo {$tipo} requiere el motivo de la referencia (1=Anula, 2=Corrige texto, 3=Corrige montos)."];
            }
        } elseif (!in_array($codRef, [1, 2, 3], true)) {
            $codRef = 0; // referencia informativa (export): código opcional
        }
        // Sólo se envían campos con contenido (un opcional vacío también rechaza).
        $ref = [
            'numero'                         => $nLin,
            'tipoDocumento'                  => $tpoRef,
            'folioReferencia'                => $folioR,
            'fechaDocumentoReferenciaString' => $fchRef,
        ];
        if ($codRef > 0)    $ref['codigoReferencia'] = (string)$codRef;
        if ($razonR !== '') $ref['razonReferencia']  = $razonR;
        $referencias[] = $ref;
    }
    if (dte_lleva_referencia($tipo) && !$referencias) {
        return ['ok' => false, 'error' => "El tipo {$tipo} requiere al menos una referencia válida (indica el tipo y el folio del documento que modifica)."];
    }

    $input = [
        'Documento' => [
            'Encabezado' => $encabezado,
            'Detalles' => $detalles,
            'Referencias' => $referencias,
            'DescuentosRecargos' => [],
        ],
        'Certificado' => ['Rut' => (string)($cfg['cert_rut'] ?? ''), 'Password' => (string)($cfg['cert_pass'] ?? '')],
    ];

    // ---------- 1) Generar DTE timbrado ----------
    $tm = ['inicio' => microtime(true)];
    $rDte = $cli->generarDTE($input);
    $tm['generar'] = microtime(true);
    if (!is_array($rDte) || !($rDte['ok'] ?? false)) {
        return ['ok' => false, 'error' => 'Error generando DTE', 'detalle' => $rDte];
    }
    $xmlDte = (string)($rDte['body'] ?? '');
    if (trim($xmlDte) === '') return ['ok' => false, 'error' => 'Trivoxa API DTE devolvió DTE vacío'];

    // ---------- 2) Generar sobre EnvioDTE ----------
    // El SII compara el RutEnvia (que sale del certificado = subjectAltName) con el RUT
    // que AUTENTICA el envío. Deben ser iguales. El titular del certificado (ROCIO) está
    // autorizado por la empresa ante el SII, así que se autentica y envía como ÉL, y el
    // emisor del documento es la empresa.
    $rutEmisorNorm = dte_norm_rut((string)($cfg['emisor_rut'] ?? $empresa['rut']));   // empresa
    $rutEnvia      = dte_norm_rut((string)($cfg['cert_rut'] ?? $rutEmisorNorm));        // titular del certificado
    $certPass      = (string)($cfg['cert_pass'] ?? '');

    $caratula = [
        'rutEmisor' => $rutEmisorNorm,
        'rutEnvia'  => $rutEnvia,
        'rutReceptor' => '60803000-K',
        'fechaResolucion' => (string)$cfgBase['fecha_resolucion'], 'numeroResolucion' => (int)$cfgBase['numero_resolucion'],
    ];
    // Autenticación del envío al SII = titular del certificado + la clave del .pfx
    // (con ese certificado se firma la semilla del token; así el SII registra el envío
    // a nombre de ROCIO, que coincide con RutEnvia).
    $auth = ['rut' => $rutEnvia, 'password' => ($certPass !== '' ? $certPass : (string)($cfgBase['api_user_pass'] ?? ''))];
    $cred = ['ruta' => (string)$cfg['cert_path'], 'password' => (string)$cfg['cert_pass'], 'rut' => (string)$cfg['cert_rut']];

    $rEnv = $cli->generarEnvio([
        'certificado' => $cred, 'tipo' => 0, 'ambiente' => 'produccion',
        'correoNotificacion' => (string)($cfgBase['correo_notificacion'] ?? ''),
        'autenticacion' => $auth, 'caratula' => $caratula, 'contenido' => base64_encode($xmlDte),
    ]);
    $tm['sobre'] = microtime(true);
    if (!is_array($rEnv) || !($rEnv['ok'] ?? false)) {
        return ['ok' => false, 'error' => 'Error generando sobre EnvioDTE', 'detalle' => $rEnv];
    }
    $xmlEnvio = (string)($rEnv['body'] ?? '');

    // ---------- 3) Enviar al SII (palena) ----------
    // EnvioType: 2 = EnvioBOLETA para boletas (39/41), 1 = EnvioDTE para el resto.
    // generarEnvio ya produjo el sobre correcto (EnvioBOLETA) auto-detectándolo;
    // el envío debe declarar el mismo tipo o el SII rechaza por "no corresponde".
    $envioTipo = in_array($tipo, [39, 41], true) ? 2 : 1;
    $rSend = $cli->enviarEnvio([
        'certificado' => $cred, 'tipo' => $envioTipo, 'ambiente' => 'produccion',
        'correoNotificacion' => (string)($cfgBase['correo_notificacion'] ?? ''),
        'autenticacion' => $auth, 'caratula' => $caratula, 'contenido' => base64_encode($xmlEnvio),
    ]);
    $tm['envio'] = microtime(true);
    $trackid = is_array($rSend) ? dte_extraer_trackid($rSend['body'] ?? null) : null;
    $subidoOk = is_array($rSend) && ($rSend['ok'] ?? false) && $trackid;

    // ---------- 3b) Verificar el estado REAL en el SII ----------
    // El TrackID sólo confirma que el SOBRE fue RECIBIDO. El SII valida el
    // schema/firma después y puede RECHAZAR (p. ej. RSC). Consultamos el
    // TrackID para no dejar como "ENVIADO" un documento que el SII rechazó.
    if (!$subidoOk) {
        $estado = 'RECHAZADO';
        $glosa  = 'Rechazado por el SII/Trivoxa API DTE: ' . json_encode($rSend, JSON_UNESCAPED_UNICODE);
    } else {
        // Sondeo con reintentos: el rechazo por schema (RSC) suele resolverse en
        // segundos. Consultamos varias veces para NO consumir folio si el SII rechaza.
        // Boletas (servidor REST del SII): el veredicto suele llegar en 3-5 s -> hasta 4 consultas (~6 s).
        // Facturas y notas: el SII tarda más (decenas de segundos); 2 consultas rápidas detectan los
        // rechazos inmediatos y el resto de la validación continúa en pantalla, sin bloquear.
        // (Antes: 6 consultas con 1,8 s de pausa = ~12 s de espera, casi siempre en vano.)
        $cons = dte_consultar_estado_sii($cli, $cfg, (string)$trackid, $tipo, 0,
            in_array($tipo, [39, 41], true) ? [1.2, 1.5, 2.0] : [1.2]);
        if ($cons['estado'] === 'RECHAZADO') {
            $estado = 'RECHAZADO';
            $glosa  = 'Rechazado por el SII: ' . (string)$cons['glosa'];
        } else {
            // ENVIADO (aceptado/procesado) o PENDIENTE (aún en proceso). En
            // ambos casos el sobre fue recibido: se registra como ENVIADO y el
            // folio se consume. Si quedó PENDIENTE, podrá reconsultarse luego.
            $estado = 'ENVIADO';
            $glosa  = null;
        }
    }
    $aceptado = ($estado === 'ENVIADO');
    // Recibido por el SII pero aún sin veredicto: se registra y la validación sigue después.
    $pendiente = $aceptado && isset($cons) && ($cons['estado'] ?? '') === 'PENDIENTE';
    $tm['veredicto'] = microtime(true);

    // ---------- 4) Registrar (transacción) ----------
    // REGLAS:
    //  - El folio SÓLO se consume (avanza el correlativo) si el SII ACEPTÓ.
    //  - Si fue RECHAZADO, igual se guarda el documento (estado=RECHAZADO) para que
    //    quede VISIBLE en el sistema con su motivo, pero NO se consume folio.
    //  - Se usa UPSERT (ON DUPLICATE KEY) para que reintentar el mismo folio
    //    sobrescriba el intento rechazado sin error "Duplicate entry".
    $now   = date('Y-m-d H:i:s');
    $rutE  = dte_norm_rut((string)$empresa['rut']);
    $rutR  = $receptor['Rut'];
    $cliId = dte_int($p['paciente_id'] ?? 0);
    $obs   = dte_str($p['observaciones'] ?? '');
    $moneda     = $exportacion ? dte_str($p['aduana']['moneda'] ?? 'USD') : 'CLP';
    $tipoCambio = $exportacion ? dte_float($p['aduana']['tipo_cambio'] ?? 0) : null;

    $conex->begin_transaction();
    try {
        // ---- (a) Documento maestro unificado (UPSERT) ----
        $st = $conex->prepare("INSERT INTO dte_documentos
            (empresa_id, tipo_dte, folio, ambiente, rut_emisor, rut_receptor, razon_receptor,
             paciente_id, fecha_emision, fecha_vencimiento, moneda, tipo_cambio,
             neto, exento, iva, total, estado, trackid, glosa_error, caf_empresa_id,
             created_at, updated_at)
            VALUES (?,?,?, 'produccion', ?,?,?, ?,?,?,?,?, ?,?,?,?, ?,?,?,?, ?,?)
            ON DUPLICATE KEY UPDATE
             rut_receptor=VALUES(rut_receptor), razon_receptor=VALUES(razon_receptor),
             paciente_id=VALUES(paciente_id), fecha_emision=VALUES(fecha_emision),
             fecha_vencimiento=VALUES(fecha_vencimiento), moneda=VALUES(moneda), tipo_cambio=VALUES(tipo_cambio),
             neto=VALUES(neto), exento=VALUES(exento), iva=VALUES(iva), total=VALUES(total),
             estado=VALUES(estado), trackid=VALUES(trackid), glosa_error=VALUES(glosa_error),
             caf_empresa_id=VALUES(caf_empresa_id), updated_at=VALUES(updated_at)");
        if (!$st) throw new RuntimeException('prepare dte_documentos: ' . $conex->error);
        $razonR  = (string)$receptor['RazonSocial'];
        // $fVenc se calculó antes de armar IdDoc (las boletas ya no lo incluyen en $idDoc).
        $cliIdDb = $cliId > 0 ? $cliId : null;
        $cafId   = (int)$caf['id'];
        $st->bind_param(
            'iiisssisssdiiiisssiss',
            $empresaId, $tipo, $folio, $rutE, $rutR, $razonR,
            $cliIdDb, $fEmis, $fVenc, $moneda, $tipoCambio,
            $neto, $exento, $iva, $total, $estado, $trackid, $glosa, $cafId,
            $now, $now
        );
        $st->execute(); $docId = (int)$st->insert_id; $st->close();
        // El id devuelto por insert_id NO es confiable tras un INSERT ... ON DUPLICATE
        // KEY UPDATE cuando la fila fue ACTUALIZADA (reintento del mismo folio): segun la
        // version/config de MySQL puede devolver 0, el id de la fila existente o el
        // proximo auto_increment. Por eso SIEMPRE resolvemos el id real por la clave
        // unica (empresa_id + tipo_dte + folio + ambiente) en vez de confiar en
        // "insert_id === 0". Antes ese chequeo fallaba en produccion (insert_id traia el
        // id existente, != 0), el bloque de limpieza no corria y cada reintento APENDIA
        // lineas de detalle al mismo documento (la boleta 39/101 quedo con 3 lineas para
        // 1 solo item, descuadrando el detalle/PDF respecto de los Totales aceptados).
        $q = $conex->prepare("SELECT id FROM dte_documentos WHERE empresa_id=? AND tipo_dte=? AND folio=? AND ambiente='produccion' LIMIT 1");
        $q->bind_param('iii', $empresaId, $tipo, $folio); $q->execute();
        $docReal = (int)($q->get_result()->fetch_assoc()['id'] ?? 0); $q->close();
        if ($docReal > 0) $docId = $docReal;
        if ($docId <= 0) throw new RuntimeException('No se pudo resolver el id del documento tras el UPSERT (folio ' . $folio . ')');
        // Detalle/referencias IDEMPOTENTES: se borra SIEMPRE lo previo de este documento
        // antes de insertar, para que el detalle refleje EXACTAMENTE lo realmente emitido
        // y la suma de lineas cuadre con los Totales, sin importar si el UPSERT resulto en
        // INSERT (documento nuevo, sin filas) o UPDATE (reintento, con filas del intento
        // anterior que hay que descartar). Se usan sentencias preparadas.
        $del = $conex->prepare("DELETE FROM dte_documento_det WHERE documento_id=?");
        $del->bind_param('i', $docId); $del->execute(); $del->close();
        $del = $conex->prepare("DELETE FROM dte_documento_ref WHERE documento_id=?");
        $del->bind_param('i', $docId); $del->execute(); $del->close();

        // ---- (b) Detalle unificado ----
        $st = $conex->prepare("INSERT INTO dte_documento_det
            (documento_id, producto_id, codigo, descripcion, cantidad, unidad,
             precio_unitario, descuento_monto, exento, total_linea)
            VALUES (?,?,?,?,?,?,?,?,?,?)");
        if (!$st) throw new RuntimeException('prepare dte_documento_det: ' . $conex->error);
        foreach ($detalle as $d) {
            $pid = (int)$d['producto_id']; $pidDb = $pid > 0 ? $pid : null;
            $cod = (string)$d['codigo']; $des = (string)$d['descripcion'];
            $can = (float)$d['cantidad']; $uni = (string)$d['unidad']; $pre = (float)$d['precio'];
            $dmo = (int)$d['descuento_total']; $exe = (int)$d['exento']; $tol = (int)$d['monto_final'];
            $st->bind_param('iissdsdiii', $docId, $pidDb, $cod, $des, $can, $uni, $pre, $dmo, $exe, $tol);
            $st->execute();
        }
        $st->close();

        // ---- (c) Referencias unificadas ----
        if ($referencias) {
            $st = $conex->prepare("INSERT INTO dte_documento_ref
                (documento_id, tipo_doc, folio, fecha, codigo, razon)
                VALUES (?,?,?,?,?,?)");
            if (!$st) throw new RuntimeException('prepare dte_documento_ref: ' . $conex->error);
            foreach ($referencias as $rf) {
                $td = (string)$rf['tipoDocumento']; $fo = (string)$rf['folioReferencia'];
                $fe = (string)$rf['fechaDocumentoReferenciaString']; $cd = (int)($rf['codigoReferencia'] ?? 0); $rz = (string)($rf['razonReferencia'] ?? '');
                $st->bind_param('isssis', $docId, $td, $fo, $fe, $cd, $rz);
                $st->execute();
            }
            $st->close();
        }

        // ---- (d) Bitácora de envío al SII ----
        $lote = 'UNIT_' . date('Ymd_His');
        $st = $conex->prepare("INSERT INTO sii_envios
            (doc_tipo, doc_id, rut_emisor, rut_receptor, folio, lote_id, trackid, estado, glosa_error, xml_dte, xml_envio, respuesta_sii, created_at, updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $resp = json_encode($rSend, JSON_UNESCAPED_UNICODE);
        $st->bind_param('iississsssssss', $tipo, $docId, $rutE, $rutR, $folio, $lote, $trackid, $estado, $glosa, $xmlDte, $xmlEnvio, $resp, $now, $now);
        $st->execute(); $envId = (int)$st->insert_id; $st->close();

        // ---- (e) Vincular el envío al documento ----
        $st = $conex->prepare("UPDATE dte_documentos SET sii_envio_id=? WHERE id=?");
        $st->bind_param('ii', $envId, $docId); $st->execute(); $st->close();

        // ---- (f) Datos extra (observaciones / pago / transporte / aduana) para la vista, best-effort ----
        $extra = [];
        if ($obs !== '')                               $extra['observaciones'] = $obs;
        $fpNum = dte_int($p['forma_pago'] ?? 0);
        if ($fpNum > 0) $extra['forma_pago'] = ($fpNum === 2 ? 'Crédito' : ($fpNum === 3 ? 'Sin costo' : 'Contado'));
        if (!empty($p['forma_pago_txt'])) $extra['forma_pago'] = dte_str($p['forma_pago_txt']);
        if ($dgPct > 0) $extra['descuento_global_pct'] = $dgPct;
        if ($tipo === 52 && !empty($p['transporte'])) $extra['transporte'] = $p['transporte'];
        if ($exportacion && !empty($p['aduana']))      $extra['aduana'] = $p['aduana'];
        if ($extra) {
            try {
                $ej = json_encode($extra, JSON_UNESCAPED_UNICODE);
                $st = $conex->prepare("UPDATE dte_documentos SET datos_extra=? WHERE id=?");
                if ($st) { $st->bind_param('si', $ej, $docId); $st->execute(); $st->close(); }
            } catch (Throwable $eX) { /* columna opcional */ }
        }

        // Consumo/liberación del folio:
        //  - Reservado por nosotros (folio automático): ya avanzó al reservar. Si el
        //    SII rechazó, se libera (retrocede solo si sigue siendo el último).
        //  - Folio explícito (reintento sin reservar): se consume solo si el SII aceptó.
        if ($folioReservado) {
            if (!$aceptado) dte_liberar_folio($conex, (int)$caf['id'], $folio);
        } else {
            if ($aceptado) dte_consumir_folio($conex, (int)$caf['id'], $folio);
        }
        $conex->commit();

        // Contabilización AUTOMÁTICA de la venta (solo si el SII aceptó). Es
        // best-effort y va DESPUÉS del commit: si falla, no rompe la emisión
        // (el asiento se puede regenerar luego con el botón de Contabilidad).
        // cont_asentar_dte es idempotente y ya excluye guías (52) y rechazados.
        if ($aceptado) {
            try {
                if (!function_exists('cont_asentar_dte')) require_once __DIR__ . '/contabilidad.php';
                cont_asentar_dte($conex, $docId);
            } catch (\Throwable $eC) {
                error_log('[dte_emitir] contabilización automática falló doc=' . $docId . ': ' . $eC->getMessage());
            }
        }

        $tm['registro'] = microtime(true);
        dte_log_tiempos($cfgBase, $tipo, $folio, $tm, $estado . ($pendiente ? ' (pendiente)' : ''));
        return ['ok' => $aceptado, 'tipo' => $tipo, 'folio' => $folio, 'trackid' => $trackid,
            'estado' => $estado, 'pendiente' => $pendiente, 'neto' => $neto, 'iva' => $iva, 'exento' => $exento, 'total' => $total,
            'documento_id' => $docId,
            'error' => $aceptado ? null : ('El SII rechazó el documento (queda guardado como RECHAZADO, folio ' . $folio . ' no consumido). Detalle: ' . $glosa),
            'moneda' => $moneda, 'sii_envio_id' => $envId, 'glosa' => $glosa];
    } catch (Throwable $e) {
        $conex->rollback();
        // El guardado falló pero ya habíamos reservado el folio: devolverlo para no
        // perderlo (retrocede solo si sigue siendo el último consumido).
        if (!empty($folioReservado)) dte_liberar_folio($conex, (int)$caf['id'], $folio);
        return ['ok' => false, 'error' => 'Error guardando: ' . $e->getMessage(), 'trackid' => $trackid];
    }
}

/**
 * Re-consulta el estado REAL de un documento ya emitido usando su TrackID y
 * actualiza dte_documentos / sii_envios si el SII cambió el veredicto (típico:
 * un envío que quedó "en proceso" y el SII terminó RECHAZÁNDOLO por schema).
 * No toca folios (el correlativo ya avanzó al aceptarse el sobre) ni documentos
 * anulados. Devuelve ['ok', 'estado', 'cambio', 'glosa', ...].
 */
/**
 * Mantiene la CONTABILIDAD alineada con el estado del DTE.
 *  - ENVIADO/ACEPTADO → debe existir asiento (se crea si falta; es idempotente).
 *  - RECHAZADO/ANULADO → el documento sale del Libro IVA, así que su asiento se ANULA.
 * Sin esto, marcar un DTE como RECHAZADO lo sacaba del libro pero dejaba el asiento
 * vivo: contabilidad y libro IVA divergían en silencio.
 * Best-effort: nunca rompe el flujo del documento.
 */
function dte_sincronizar_asiento(mysqli $conex, int $docId, string $estado, string $motivo = ''): void {
    try {
        if (!function_exists('cont_asentar_dte')) require_once __DIR__ . '/contabilidad.php';
        $e = strtoupper($estado);
        if (in_array($e, ['RECHAZADO', 'ANULADO'], true)) {
            cont_anular_asiento($conex, 'dte', $docId, $motivo !== '' ? $motivo : $e);
        } elseif (in_array($e, ['ENVIADO', 'ACEPTADO'], true)) {
            cont_asentar_dte($conex, $docId);   // idempotente; recrea si estaba anulado
        }
    } catch (\Throwable $e) {
        error_log('[dte_sincronizar_asiento] doc=' . $docId . ': ' . $e->getMessage());
    }
}

function dte_resync_estado(mysqli $conex, array $cfgBase, int $docId, int $intentos = 2): array {
    $st = $conex->prepare("SELECT id, empresa_id, tipo_dte, folio, trackid, estado, caf_empresa_id FROM dte_documentos WHERE id=? LIMIT 1");
    if (!$st) return ['ok' => false, 'error' => 'No se pudo consultar el documento'];
    $st->bind_param('i', $docId); $st->execute();
    $doc = $st->get_result()->fetch_assoc(); $st->close();
    if (!$doc) return ['ok' => false, 'error' => 'El documento no existe'];

    $estadoActual = strtoupper((string)$doc['estado']);
    if ($estadoActual === 'ANULADO') {
        return ['ok' => true, 'estado' => 'ANULADO', 'cambio' => false, 'mensaje' => 'El documento está anulado.'];
    }
    $trackid = trim((string)$doc['trackid']);
    if ($trackid === '') {
        return ['ok' => false, 'error' => 'El documento no tiene TrackID: no fue enviado al SII.'];
    }

    try {
        [$cfg] = dte_resolver_cfg($conex, $cfgBase, (int)$doc['empresa_id'], (int)$doc['tipo_dte']);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }

    $cli  = new TrivoxaApiClient($cfg);
    $cons = dte_consultar_estado_sii($cli, $cfg, $trackid, (int)$doc['tipo_dte'], max(1, $intentos));

    if ($cons['estado'] === 'PENDIENTE') {
        return ['ok' => true, 'estado' => $estadoActual, 'cambio' => false, 'pendiente' => true,
                'mensaje' => 'El SII aún está validando el envío. Intenta nuevamente en unos minutos.'];
    }

    $nuevo = $cons['estado']; // ENVIADO o RECHAZADO
    $glosa = ($nuevo === 'RECHAZADO') ? ('Rechazado por el SII: ' . (string)$cons['glosa']) : null;
    $now   = date('Y-m-d H:i:s');

    $up = $conex->prepare("UPDATE dte_documentos SET estado=?, glosa_error=?, updated_at=? WHERE id=?");
    if ($up) { $up->bind_param('sssi', $nuevo, $glosa, $now, $docId); $up->execute(); $up->close(); }

    $dt = (int)$doc['tipo_dte'];
    $up = $conex->prepare("UPDATE sii_envios SET estado=?, glosa_error=?, updated_at=? WHERE doc_id=? AND doc_tipo=?");
    if ($up) { $up->bind_param('sssii', $nuevo, $glosa, $now, $docId, $dt); $up->execute(); $up->close(); }

    // El documento cambió de estado: la contabilidad debe seguirlo.
    // RECHAZADO sale del Libro IVA, así que su asiento TAMBIÉN debe anularse
    // (si no, contabilidad ≠ libro IVA). Si vuelve a ENVIADO, se re-contabiliza.
    dte_sincronizar_asiento($conex, $docId, $nuevo, 'SII: ' . $nuevo);

    // Si el documento pasó a RECHAZADO, se libera el folio que había quedado
    // consumido, para que vuelva a estar disponible (no se pierde el correlativo).
    $folioLiberado = false;
    if ($nuevo === 'RECHAZADO') {
        $folioLiberado = dte_liberar_folio($conex, (int)($doc['caf_empresa_id'] ?? 0), (int)$doc['folio']);
    } elseif ($nuevo === 'ENVIADO' && $estadoActual !== 'ENVIADO') {
        // El documento pasó a ENVIADO (aceptado por el SII). Si venía de un estado
        // que había liberado el folio (p. ej. un falso RECHAZADO por el bug de
        // interpretación de boletas), hay que CONSUMIR el folio para que el
        // correlativo refleje que ya se usó. dte_consumir_folio usa
        // GREATEST(folio_actual, folio+1): es idempotente y no retrocede el
        // correlativo si otra emisión ya avanzó más.
        dte_consumir_folio($conex, (int)($doc['caf_empresa_id'] ?? 0), (int)$doc['folio']);
    }

    $msg = $estadoActual !== $nuevo
        ? "El estado cambió de {$estadoActual} a {$nuevo}."
        : "El estado sigue siendo {$nuevo}.";
    if ($folioLiberado) $msg .= " Se liberó el folio {$doc['folio']} para reutilizarlo.";

    return ['ok' => true, 'estado' => $nuevo, 'glosa' => $glosa,
            'cambio' => ($estadoActual !== $nuevo), 'folio_liberado' => $folioLiberado,
            'mensaje' => $msg];
}

/**
 * Cambia MANUALMENTE el estado de un documento (respaldo cuando el SII notificó
 * por correo un rechazo que la consulta automática no logra confirmar, o para
 * corregir a mano). Sólo permite ENVIADO/RECHAZADO (nunca ANULADO, que tiene su
 * propio flujo y es sólo para guías 52). Si se marca RECHAZADO, libera el folio.
 */
function dte_estado_manual(mysqli $conex, array $cfgBase, int $docId, string $nuevo): array {
    $nuevo = strtoupper(trim($nuevo));
    if (!in_array($nuevo, ['ENVIADO', 'RECHAZADO'], true)) {
        return ['ok' => false, 'error' => 'Estado no permitido. Sólo Enviado o Rechazado.'];
    }

    $st = $conex->prepare("SELECT id, tipo_dte, folio, estado, caf_empresa_id FROM dte_documentos WHERE id=? LIMIT 1");
    if (!$st) return ['ok' => false, 'error' => 'No se pudo consultar el documento'];
    $st->bind_param('i', $docId); $st->execute();
    $doc = $st->get_result()->fetch_assoc(); $st->close();
    if (!$doc) return ['ok' => false, 'error' => 'El documento no existe'];

    $estadoActual = strtoupper((string)$doc['estado']);
    if ($estadoActual === 'ANULADO') {
        return ['ok' => false, 'error' => 'El documento está anulado y no puede cambiarse.'];
    }

    $now   = date('Y-m-d H:i:s');
    $glosa = ($nuevo === 'RECHAZADO')
        ? 'Marcado como RECHAZADO manualmente (rechazo notificado por el SII).'
        : null;

    $up = $conex->prepare("UPDATE dte_documentos SET estado=?, glosa_error=?, updated_at=? WHERE id=?");
    if ($up) { $up->bind_param('sssi', $nuevo, $glosa, $now, $docId); $up->execute(); $up->close(); }

    $dt = (int)$doc['tipo_dte'];
    $up = $conex->prepare("UPDATE sii_envios SET estado=?, glosa_error=?, updated_at=? WHERE doc_id=? AND doc_tipo=?");
    if ($up) { $up->bind_param('sssii', $nuevo, $glosa, $now, $docId, $dt); $up->execute(); $up->close(); }

    // La contabilidad sigue al documento (RECHAZADO → anula el asiento; ENVIADO → lo recrea).
    dte_sincronizar_asiento($conex, $docId, $nuevo, 'Cambio manual a ' . $nuevo);

    $folioLiberado = false;
    if ($nuevo === 'RECHAZADO') {
        $folioLiberado = dte_liberar_folio($conex, (int)($doc['caf_empresa_id'] ?? 0), (int)$doc['folio']);
    }

    $msg = "Estado actualizado manualmente a {$nuevo}.";
    if ($folioLiberado) $msg .= " Se liberó el folio {$doc['folio']} para reutilizarlo.";

    return ['ok' => true, 'estado' => $nuevo, 'cambio' => ($estadoActual !== $nuevo),
            'folio_liberado' => $folioLiberado, 'manual' => true, 'mensaje' => $msg];
}

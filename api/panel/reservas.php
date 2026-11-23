<?php
/**
 * API del panel · reservas. Requiere sesión + CSRF (header X-CSRF-Token).
 * GET  ?id=                 -> detalle de una reserva (con ocupación del box y su serie)
 * GET  ?box=&fecha=&hora=   -> todas las reservas de un box en una hora
 * POST action=crear         -> agenda una hora (respeta el cupo máximo por box)
 * POST action=crear_rango   -> agenda por rango de fechas (cada profesional queda en su serie)
 * POST action=estado        -> cambia el estado (confirmada/realizada/cancelada/no_show)
 * POST action=asignar       -> cambia profesional / paciente de una reserva
 * POST action=masivo_preview-> revisa qué horas se cancelarían o traspasarían (no modifica)
 * POST action=masivo_aplicar-> cancela o traspasa en bloque (queda registrado en reservas_movimientos)
 * POST action=reagendar     -> cambia fecha/hora/box conservando su cobro
 * Cancelar o traspasar horas YA COBRADAS exige cobro_accion (devolver con nota de crédito,
 * mantener el cobro, ceder la hora o cobrársela al nuevo): sin ella se responde 409 + el impacto.
 */
declare(strict_types=1);

require_once APP_ROOT . '/lib/panel.php';
require_once APP_ROOT . '/lib/pacientes.php';

requireAuthJson();

function panel_csrf_ok(): bool
{
    $h = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return csrf_check($h);
}

/** Mensaje legible cuando el box no tiene cupo en una hora. */
function msgSinCupo(string $boxSlug, string $hora): string
{
    return 'El box ' . nombreBox($boxSlug) . ' ya tiene su cupo completo (' . boxCupoMax() . ' de ' . boxCupoMax() . ') a las ' . $hora . ' h.';
}

/** Precio referencial: tarifa "Por hora" del box. */
function precioPorHoraBox(array $box): ?int
{
    foreach ($box['tarifas'] as $t) {
        if (($t['label'] ?? '') === 'Por hora') {
            return parseClp($t['precio']);
        }
    }
    return null;
}

/** Profesionales elegidos en el cuerpo (uno o varios). [null] = sin asignar. */
function profesionalesDelCuerpo(array $body): array
{
    $profs = array_values(array_unique(array_filter(array_map('intval', (array) ($body['profesionales'] ?? [])), fn($p) => $p > 0)));
    if (!$profs && ($body['profesional_id'] ?? '') !== '') {
        $profs = [(int) $body['profesional_id']];
    }
    return $profs ?: [null];
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Detalle de un slot: todas las reservas de un box/fecha/hora (hasta el cupo).
    $box = trim((string) ($_GET['box'] ?? ''));
    $fechaQ = trim((string) ($_GET['fecha'] ?? ''));
    $horaQ = trim((string) ($_GET['hora'] ?? ''));
    if ($box !== '' && $fechaQ !== '' && $horaQ !== '') {
        $reservas = reservasEnSlot($box, $fechaQ, substr($horaQ, 0, 5));
        json_response(['ok' => true, 'box' => $box, 'box_nombre' => nombreBox($box), 'fecha' => $fechaQ,
            'hora' => substr($horaQ, 0, 5), 'cupo' => boxCupoMax(), 'reservas' => $reservas]);
    }

    $id = (int) ($_GET['id'] ?? 0);
    $r = getReserva($id);
    if (!$r) {
        json_response(['error' => 'Reserva no encontrada'], 404);
    }
    $r['box_nombre'] = nombreBox($r['box_slug']);
    $r['profesional_nombre'] = nombreProfesional($r['profesional_id'] !== null ? (int) $r['profesional_id'] : null);
    $r['hora'] = $r['hora'] ? substr($r['hora'], 0, 5) : null;
    $r['precio_fmt'] = $r['precio_clp'] ? clp((int) $r['precio_clp']) : null;
    $r['tipo_nombre'] = !empty($r['tipo_atencion']) ? (RESERVA_TIPOS_ATENCION[$r['tipo_atencion']] ?? null) : null;
    $r['paciente_nombre'] = null;
    if (!empty($r['paciente_id'])) {
        $c = getPaciente((int) $r['paciente_id']);
        $r['paciente_nombre'] = $c['nombre'] ?? null;
    }
    $r['cupo'] = boxCupoMax();
    $r['ocupacion'] = $r['hora'] ? boxOcupacion((string) $r['box_slug'], (string) $r['fecha'], $r['hora']) : 0;
    // Cobro: sesión (boleta del paciente) y arriendo (factura del cliente del profesional).
    require_once APP_ROOT . '/lib/cobros.php';
    $rc = reservasCobro([$id])[0] ?? null;
    $r['cobro'] = $rc ? cobroEstadoReserva($rc) : ['sesion' => null, 'arriendo' => null];
    $r['clientes'] = [];
    if (!empty($r['profesional_id'])) {
        try {
            $cs = db()->prepare("SELECT c.id, c.razon_social FROM clientes_profesionales cp JOIN clientes c ON c.id = cp.cliente_id WHERE cp.profesional_id = ? AND c.activo = 1 ORDER BY c.razon_social");
            $cs->execute([(int) $r['profesional_id']]);
            $r['clientes'] = $cs->fetchAll();
        } catch (Throwable $e) {
        }
    }
    $r['es_admin'] = isAdmin();
    // Serie (horas creadas juntas por rango): cuántas siguen vigentes a futuro.
    $r['serie'] = null;
    if (!empty($r['serie_id'])) {
        $s = reservasParaMasivo(['serie_id' => $r['serie_id']]);
        if ($s['ok'] && count($s['filas']) > 1) {
            $f = $s['filas'];
            $r['serie'] = ['id' => $r['serie_id'], 'total' => count($f), 'desde' => $f[0]['fecha'], 'hasta' => $f[count($f) - 1]['fecha']];
        }
    }
    json_response(['ok' => true, 'reserva' => $r]);
}

if ($method === 'POST') {
    if (!panel_csrf_ok()) {
        json_response(['error' => 'Sesión expirada. Recarga la página.'], 419);
    }
    $body = read_json_body() ?? [];
    $action = $body['action'] ?? '';

    if ($action === 'estado') {
        $id = (int) ($body['id'] ?? 0);
        $estado = $body['estado'] ?? '';
        if (!in_array($estado, ['pendiente', 'confirmada', 'realizada', 'cancelada', 'no_show'], true)) {
            json_response(['error' => 'Estado inválido'], 400);
        }
        $r = getReserva($id);
        if (!$r) {
            json_response(['error' => 'Reserva no encontrada'], 404);
        }
        require_once APP_ROOT . '/lib/cobros.php';
        $cancela = $estado === 'cancelada' && $r['estado'] !== 'cancelada';
        $impacto = $cancela ? cobroImpacto([$id], 'cancelar') : ['requiere' => false];
        $cobroAccion = (string) ($body['cobro_accion'] ?? '');
        if ($impacto['requiere'] && $cobroAccion === '') {
            json_response(['error' => 'Esta hora ya está cobrada: elige qué hacer con el cobro.', 'requiere_cobro' => true, 'operacion' => 'cancelar', 'cobro' => $impacto], 409);
        }
        $pdo = db();
        try {
            $pdo->beginTransaction();
            if ($impacto['requiere']) {
                $rc = cobroAplicar([$id], 'cancelar', $cobroAccion, (string) ($body['cobro_motivo'] ?? ''));
                if (!$rc['ok']) {
                    throw new RuntimeException($rc['error']);
                }
            }
            $st = $pdo->prepare("UPDATE reservas SET estado = ?" . ($r['estado'] === 'cancelada' && $estado !== 'cancelada' ? ', cobro_mantenido = 0' : '') . " WHERE id = ?");
            $st->execute([$estado, $id]);
            if ($cancela) {
                registrarMovimientoReserva($id, 'cancelacion', $r['profesional_id'] !== null ? (int) $r['profesional_id'] : null, null, null, 'Cancelación individual');
            } elseif ($r['estado'] === 'cancelada' && $estado !== 'cancelada') {
                registrarMovimientoReserva($id, 'reactivacion', $r['profesional_id'] !== null ? (int) $r['profesional_id'] : null, null, null, 'Reactivada como ' . $estado);
            }
            $pdo->commit();
            json_response(['ok' => true]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof RuntimeException) {
                json_response(['error' => $e->getMessage()], 400);
            }
            if (esErrorCupoBox($e)) {
                json_response(['error' => 'No se puede reactivar: ' . msgSinCupo((string) $r['box_slug'], substr((string) $r['hora'], 0, 5))], 409);
            }
            if (($m = errorDiaCerrado($e, (string) $r['fecha'])) !== null) {
                json_response(['error' => 'No se puede reactivar: ' . $m], 409);
            }
            json_response(['error' => 'No se pudo actualizar'], 500);
        }
    }

    if ($action === 'tipo_atencion') {
        $id = (int) ($body['id'] ?? 0);
        if (!getReserva($id)) {
            json_response(['error' => 'Reserva no encontrada'], 404);
        }
        $raw = trim((string) ($body['tipo_atencion'] ?? ''));
        $tipo = tipoAtencionValido($raw);
        if ($raw !== '' && $tipo === null) {
            json_response(['error' => 'Tipo de atención inválido'], 400);
        }
        try {
            db()->prepare('UPDATE reservas SET tipo_atencion = ? WHERE id = ?')->execute([$tipo, $id]);
        } catch (Throwable $e) {
            json_response(['error' => 'No se pudo guardar el tipo de atención.'], 500);
        }
        json_response(['ok' => true, 'tipo_atencion' => $tipo, 'tipo_nombre' => $tipo ? RESERVA_TIPOS_ATENCION[$tipo] : null]);
    }

    if ($action === 'asignar') {
        $id = (int) ($body['id'] ?? 0);
        $r = getReserva($id);
        if (!$r) {
            json_response(['error' => 'Reserva no encontrada'], 404);
        }
        $profId = ($body['profesional_id'] ?? '') !== '' ? (int) $body['profesional_id'] : null;
        $cliId = ($body['paciente_id'] ?? '') !== '' ? (int) $body['paciente_id'] : null;
        require_once APP_ROOT . '/lib/cobros.php';
        // Una sesión pagada pertenece a su paciente: no se le cambia el paciente.
        if (!empty($r['boleta_id']) && (int) ($r['paciente_id'] ?? 0) !== (int) ($cliId ?? 0)) {
            json_response(['error' => 'Esta sesión ya está pagada con una boleta de su paciente: no se puede cambiar el paciente. Si corresponde, devuelve el pago con una nota de crédito desde la boleta.'], 409);
        }
        $profAntesA = $r['profesional_id'] !== null ? (int) $r['profesional_id'] : null;
        $impacto = $profAntesA !== $profId ? cobroImpacto([$id], 'traspasar') : ['requiere' => false];
        $cobroAccion = (string) ($body['cobro_accion'] ?? '');
        if ($impacto['requiere'] && $cobroAccion === '') {
            json_response(['error' => 'Esta hora ya está facturada: elige quién la paga.', 'requiere_cobro' => true, 'operacion' => 'traspasar', 'cobro' => $impacto], 409);
        }

        // El profesional no puede quedar en dos lugares a la misma hora.
        if ($profId !== null && !empty($r['hora'])) {
            try {
                $c = db()->prepare("SELECT COUNT(*) FROM reservas WHERE profesional_id=? AND fecha=? AND hora=? AND estado<>'cancelada' AND id<>?");
                $c->execute([$profId, $r['fecha'], $r['hora'], $id]);
                if ((int) $c->fetchColumn() > 0) {
                    json_response(['error' => 'Ese profesional ya tiene una hora tomada en ese horario.'], 409);
                }
            } catch (Throwable $e) {
                // el UNIQUE uq_slot_prof protege igualmente más abajo
            }
        }
        $pdo = db();
        try {
            $pdo->beginTransaction();
            if ($impacto['requiere']) {
                $rc = cobroAplicar([$id], 'traspasar', $cobroAccion, (string) ($body['cobro_motivo'] ?? ''));
                if (!$rc['ok']) {
                    throw new RuntimeException($rc['error']);
                }
            }
            $st = $pdo->prepare("UPDATE reservas SET profesional_id=?, paciente_id=? WHERE id=?");
            $st->execute([$profId, $cliId, $id]);
            $pdo->commit();
            $profAntes = $r['profesional_id'] !== null ? (int) $r['profesional_id'] : null;
            if ($profAntes !== $profId) {
                registrarMovimientoReserva($id, 'traspaso', $profAntes, $profId, null, 'Traspaso individual');
            }
            json_response(['ok' => true]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof PDOException && ($e->errorInfo[1] ?? 0) === 1062) {
                json_response(['error' => 'Ese profesional ya tiene una hora tomada en ese horario.'], 409);
            }
            json_response(['error' => $e instanceof RuntimeException ? $e->getMessage() : 'No se pudo asignar.'], $e instanceof RuntimeException ? 400 : 500);
        }
    }

    if ($action === 'crear') {
        $boxSlug = trim((string) ($body['box_slug'] ?? ''));
        $fecha = trim((string) ($body['fecha'] ?? ''));
        $hora = trim((string) ($body['hora'] ?? ''));
        $nombre = trim((string) ($body['nombre'] ?? ''));
        $email = trim((string) ($body['email'] ?? ''));
        $telefono = trim((string) ($body['telefono'] ?? ''));
        $modalidad = trim((string) ($body['modalidad_label'] ?? '')) ?: 'Reserva manual';
        $cliId = ($body['paciente_id'] ?? '') !== '' ? (int) $body['paciente_id'] : null;
        $force = !empty($body['force']);
        $profs = profesionalesDelCuerpo($body); // una reserva por profesional
        $tipoAt = tipoAtencionValido($body['tipo_atencion'] ?? null);

        if ($boxSlug === '' || getBoxBySlug($GLOBALS['BOXES'], $boxSlug) === null) {
            json_response(['error' => 'Selecciona un box válido'], 400);
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || $fecha < date('Y-m-d')) {
            json_response(['error' => 'Fecha inválida'], 400);
        }
        if (!in_array($hora, horasAgenda(), true)) {
            json_response(['error' => 'Hora inválida'], 400);
        }
        // Domingo, día sin atención o feriado no trabajado: límite duro (no se puede forzar).
        if (($cerrado = diaCerradoMotivo($fecha)) !== null) {
            json_response(['error' => $cerrado], 409);
        }
        // Hora fuera del horario del centro («Días y horarios»): límite duro.
        if (($fueraH = horaFueraHorarioMotivo($fecha, $hora)) !== null) {
            json_response(['error' => $fueraH], 409);
        }
        if ($nombre === '') {
            json_response(['error' => 'Falta el nombre'], 400);
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_response(['error' => 'Correo inválido'], 400);
        }

        // Cupo del box: límite duro (no se puede forzar).
        $cupo = boxCupoMax();
        $libres = $cupo - boxOcupacion($boxSlug, $fecha, $hora);
        if ($libres <= 0) {
            json_response(['error' => msgSinCupo($boxSlug, $hora)], 409);
        }
        if (count($profs) > $libres) {
            json_response(['error' => 'En el box ' . nombreBox($boxSlug) . ' a las ' . $hora . ' h solo queda ' . $libres
                . ' cupo' . ($libres === 1 ? '' : 's') . ' (máximo ' . $cupo . ' por hora) y marcaste ' . count($profs) . ' profesionales.'], 409);
        }

        // Profesional ya ocupado a esa hora: no bloquea, solo avisa (se puede confirmar).
        $conflictos = [];
        try {
            $cp = db()->prepare("SELECT COUNT(*) FROM reservas WHERE profesional_id=? AND fecha=? AND hora=? AND estado<>'cancelada'");
            foreach ($profs as $pid) {
                if ($pid !== null) {
                    $cp->execute([$pid, $fecha, $hora]);
                    if ((int) $cp->fetchColumn() > 0) {
                        $conflictos[] = nombreProfesional($pid) . ' ya tiene una hora tomada a las ' . $hora . ' h.';
                    }
                    // Fuera de su horario de atención: no bloquea, se avisa y se puede confirmar.
                    if (($avH = horarioProfesionalAviso((int) $pid, $fecha, $hora)) !== null) {
                        $conflictos[] = $avH;
                    }
                }
            }
        } catch (Throwable $e) {
        }
        if ($conflictos && !$force) {
            json_response(['needs_confirm' => true, 'conflictos' => $conflictos]);
        }

        $precio = precioPorHoraBox(getBoxBySlug($GLOBALS['BOXES'], $boxSlug));
        $pdo = db();
        try {
            $pdo->beginTransaction();
            // Bloquea las reservas de ese box/hora para que dos agendamientos simultáneos no superen el cupo.
            $lk = $pdo->prepare("SELECT id FROM reservas WHERE box_slug=? AND fecha=? AND hora=? AND estado<>'cancelada' FOR UPDATE");
            $lk->execute([$boxSlug, $fecha, $hora]);
            if (count($lk->fetchAll()) + count($profs) > $cupo) {
                $pdo->rollBack();
                json_response(['error' => msgSinCupo($boxSlug, $hora)], 409);
            }
            $ins = $pdo->prepare(
                "INSERT INTO reservas
                    (publico_id, box_slug, profesional_id, paciente_id, modalidad_label, tipo_atencion, precio_clp, fecha, hora, nombre, email, telefono, estado, origen)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?, 'confirmada', 'panel')"
            );
            $lastId = 0;
            foreach ($profs as $pid) {
                $ins->execute([substr(bin2hex(random_bytes(8)), 0, 16), $boxSlug, $pid, $cliId,
                    mb_substr($modalidad, 0, 120, 'UTF-8'), $tipoAt, $precio, $fecha, $hora, mb_substr($nombre, 0, 200, 'UTF-8'),
                    $email !== '' ? mb_substr($email, 0, 200, 'UTF-8') : '', $telefono !== '' ? mb_substr($telefono, 0, 60, 'UTF-8') : '']);
                $lastId = (int) $pdo->lastInsertId();
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (esErrorCupoBox($e)) {
                json_response(['error' => msgSinCupo($boxSlug, $hora)], 409);
            }
            if (($m = errorDiaCerrado($e, $fecha)) !== null) {
                json_response(['error' => $m], 409);
            }
            json_response(['error' => 'No se pudo agendar.'], 500);
        }
        json_response(['ok' => true, 'id' => $lastId, 'creadas' => count($profs), 'conflictos' => $conflictos,
            'aviso_feriado' => feriadoTrabajadoAviso($fecha)]);
    }

    if ($action === 'crear_rango') {
        $boxSlug = trim((string) ($body['box_slug'] ?? ''));
        // Horas a reservar en cada día: lista (todas o específicas). Compatibilidad con 'hora' simple.
        $horas = array_values(array_unique(array_filter(
            array_map(fn($h) => trim((string) $h), (array) ($body['horas'] ?? [])),
            fn($h) => in_array($h, horasAgenda(), true)
        )));
        if (!$horas && in_array(trim((string) ($body['hora'] ?? '')), horasAgenda(), true)) {
            $horas = [trim((string) $body['hora'])];
        }
        $desde = trim((string) ($body['fecha_desde'] ?? ''));
        $hasta = trim((string) ($body['fecha_hasta'] ?? ''));
        $nombre = trim((string) ($body['nombre'] ?? ''));
        $email = trim((string) ($body['email'] ?? ''));
        $telefono = trim((string) ($body['telefono'] ?? ''));
        $cliId = ($body['paciente_id'] ?? '') !== '' ? (int) $body['paciente_id'] : null;
        $modalidad = trim((string) ($body['modalidad_label'] ?? '')) ?: 'Reserva manual';
        $force = !empty($body['force']);
        $profs = profesionalesDelCuerpo($body);
        $tipoAt = tipoAtencionValido($body['tipo_atencion'] ?? null);
        // Días de la semana permitidos (1=Lun .. 6=Sáb). Vacío = todos. El domingo nunca (centro cerrado).
        $dias = array_values(array_filter(array_map('intval', (array) ($body['dias'] ?? [])), fn($d) => $d >= 1 && $d <= 6));
        if (!$dias) {
            $dias = [1, 2, 3, 4, 5, 6];
        }

        if ($boxSlug === '' || getBoxBySlug($GLOBALS['BOXES'], $boxSlug) === null) {
            json_response(['error' => 'Selecciona un box válido'], 400);
        }
        if (!$horas) {
            json_response(['error' => 'Elige al menos una hora válida.'], 400);
        }
        if ($nombre === '') {
            json_response(['error' => 'Falta el nombre'], 400);
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_response(['error' => 'Correo inválido'], 400);
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
            json_response(['error' => 'Rango de fechas inválido'], 400);
        }
        if ($desde < date('Y-m-d')) {
            $desde = date('Y-m-d');
        }
        if ($hasta < $desde) {
            json_response(['error' => 'La fecha "hasta" debe ser igual o posterior a "desde".'], 400);
        }
        try {
            $dIni = new DateTimeImmutable($desde);
            $dFin = new DateTimeImmutable($hasta);
        } catch (Throwable $e) {
            json_response(['error' => 'Rango de fechas inválido'], 400);
        }
        if ((int) $dIni->diff($dFin)->days > 366) {
            json_response(['error' => 'El rango no puede superar 1 año.'], 400);
        }

        $precio = precioPorHoraBox(getBoxBySlug($GLOBALS['BOXES'], $boxSlug));
        $cupo = boxCupoMax();
        $chkBox = db()->prepare("SELECT COUNT(*) FROM reservas WHERE box_slug=? AND fecha=? AND hora=? AND estado<>'cancelada'");
        $chkProf = db()->prepare("SELECT COUNT(*) FROM reservas WHERE profesional_id=? AND fecha=? AND hora=? AND estado<>'cancelada'");

        $MAX_CREAR = 1000; // tope de seguridad por operación
        // Combos (fecha, hora, profesional). El cupo del box es un límite duro (esos horarios
        // se omiten); un profesional ya ocupado solo genera aviso (se puede confirmar).
        $combos = []; $conflictos = []; $nConf = 0; $topado = false;
        $sinCupo = 0; $sinCupoMuestra = [];
        // Feriados del rango: los no trabajados se omiten; los trabajados se avisan.
        $ferRango = feriadosEntre($dIni->format('Y-m-d'), $dFin->format('Y-m-d'));
        $ferCerrados = []; $ferTrabajados = [];
        $fueraCentro = 0; $diasSinAtencion = [];
        for ($d = $dIni; $d <= $dFin; $d = $d->modify('+1 day')) {
            if (!in_array((int) $d->format('N'), $dias, true)) {
                continue;
            }
            $f = $d->format('Y-m-d');
            // Día en que el centro no atiende según «Días y horarios»: se omite.
            if (!horarioDiaAbierto((int) $d->format('N'))) {
                $diasSinAtencion[(int) $d->format('N')] = HORARIO_DIAS_PLURAL[(int) $d->format('N')];
                continue;
            }
            $horasDia = horasDelDia($f);
            if (isset($ferRango[$f])) {
                $txtFer = date('d-m-Y', strtotime($f)) . ' · ' . $ferRango[$f]['nombre'];
                if (!$ferRango[$f]['se_trabaja']) {
                    $ferCerrados[] = $txtFer;
                    continue;
                }
                $ferTrabajados[] = $txtFer;
            }
            foreach ($horas as $hh) {
                if (!in_array($hh, $horasDia, true)) {
                    $fueraCentro++;   // fuera del horario del centro ese día: se omite
                    continue;
                }
                $chkBox->execute([$boxSlug, $f, $hh]);
                $libres = $cupo - (int) $chkBox->fetchColumn();
                foreach ($profs as $pid) {
                    if (count($combos) >= $MAX_CREAR) { $topado = true; break 3; }
                    if ($libres <= 0) {
                        $sinCupo++;
                        if (count($sinCupoMuestra) < 12) {
                            $sinCupoMuestra[] = $f . ' ' . $hh . ' h · box lleno' . ($pid !== null ? ' (' . nombreProfesional($pid) . ')' : '');
                        }
                        continue;
                    }
                    $libres--;
                    if ($pid !== null) {
                        $chkProf->execute([$pid, $f, $hh]);
                        if ((int) $chkProf->fetchColumn() > 0) {
                            $nConf++;
                            if (count($conflictos) < 12) {
                                $conflictos[] = $f . ' ' . $hh . ' h · ' . nombreProfesional($pid) . ' ocupado/a';
                            }
                        } elseif (!horarioProfesionalAtiende((int) $pid, $f, $hh)) {
                            $nConf++;
                            if (count($conflictos) < 12) {
                                $conflictos[] = $f . ' ' . $hh . ' h · ' . nombreProfesional($pid) . ' fuera de su horario';
                            }
                        }
                    }
                    $combos[] = [$f, $hh, $pid];
                }
            }
        }
        if (!$combos) {
            if ($diasSinAtencion && !$ferCerrados && $sinCupo === 0 && $fueraCentro === 0) {
                json_response(['error' => 'No se agendó nada: el centro no atiende los ' . implode(', ', $diasSinAtencion) . ' (según «Días y horarios»).'], 409);
            }
            if ($fueraCentro > 0 && !$ferCerrados && $sinCupo === 0) {
                json_response(['error' => 'No se agendó nada: las horas elegidas están fuera del horario de atención del centro (' . horarioCentroResumen() . ').'], 409);
            }
            if ($ferCerrados && $sinCupo === 0) {
                json_response(['error' => 'No se agendó nada: los días elegidos son feriados no trabajados (' . implode(', ', $ferCerrados) . ').'], 409);
            }
            if ($sinCupo > 0) {
                json_response(['error' => 'No se agendó nada: en todos los horarios elegidos el box ya tiene su cupo completo (' . $cupo . ' por hora).'], 409);
            }
            json_response(['error' => 'No hay horarios para los días elegidos.'], 400);
        }
        // Profesionales ocupados: avisar sin crear nada hasta que se confirme.
        if ($nConf > 0 && !$force) {
            json_response(['needs_confirm' => true, 'conflictos' => $conflictos, 'n_conflictos' => $nConf,
                'total' => count($combos), 'sin_cupo' => $sinCupo, 'topado' => $topado,
                'feriados_cerrados' => $ferCerrados, 'feriados_trabajados' => $ferTrabajados,
                'fuera_horario_centro' => $fueraCentro, 'dias_sin_atencion' => array_values($diasSinAtencion)]);
        }

        // Una serie por profesional: luego se puede cancelar o traspasar completa.
        $series = [];
        foreach ($profs as $pid) {
            $series[$pid ?? 0] = substr(bin2hex(random_bytes(8)), 0, 16);
        }
        $pdo = db();
        $ins = $pdo->prepare(
            "INSERT INTO reservas
                (publico_id, box_slug, profesional_id, paciente_id, modalidad_label, tipo_atencion, precio_clp, fecha, hora, nombre, email, telefono, estado, origen, serie_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?, 'confirmada', 'panel', ?)"
        );
        $nombreDb = mb_substr($nombre, 0, 200, 'UTF-8');
        $emailDb = $email !== '' ? mb_substr($email, 0, 200, 'UTF-8') : '';
        $telDb = $telefono !== '' ? mb_substr($telefono, 0, 60, 'UTF-8') : '';
        $modDb = mb_substr($modalidad, 0, 120, 'UTF-8');
        $creadas = 0;
        $pdo->beginTransaction();
        foreach ($combos as $c) {
            try {
                $ins->execute([substr(bin2hex(random_bytes(8)), 0, 16), $boxSlug, $c[2], $cliId, $modDb, $tipoAt, $precio, $c[0], $c[1], $nombreDb, $emailDb, $telDb, $series[$c[2] ?? 0]]);
                $creadas++;
            } catch (Throwable $e) {
                if (esErrorCupoBox($e)) {
                    $sinCupo++; // se llenó entre la revisión y el guardado
                } elseif (errorDiaCerrado($e) !== null) {
                    $ferCerrados[] = date('d-m-Y', strtotime($c[0])) . ' · día cerrado';
                }
            }
        }
        $pdo->commit();
        if ($creadas === 0) {
            json_response(['error' => 'No se pudo crear ninguna reserva.'], 409);
        }
        json_response(['ok' => true, 'creadas' => $creadas, 'n_conflictos' => $nConf, 'sin_cupo' => $sinCupo,
            'sin_cupo_muestra' => $sinCupoMuestra, 'topado' => $topado,
            'feriados_cerrados' => array_values(array_unique($ferCerrados)), 'feriados_trabajados' => $ferTrabajados,
            'fuera_horario_centro' => $fueraCentro, 'dias_sin_atencion' => array_values($diasSinAtencion)]);
    }

    if ($action === 'reagendar') {
        require_once APP_ROOT . '/lib/cobros.php';
        $res = reagendarReserva((int) ($body['id'] ?? 0), (string) ($body['fecha'] ?? ''), (string) ($body['hora'] ?? ''), (string) ($body['box_slug'] ?? ''));
        json_response($res['ok'] ? $res : ['error' => $res['error']], $res['ok'] ? 200 : 409);
    }

    if ($action === 'masivo_preview' || $action === 'masivo_aplicar') {
        $res = reservasMasivo($body, $action === 'masivo_aplicar');
        json_response($res['data'], $res['status']);
    }

    json_response(['error' => 'Acción inválida'], 400);
}

json_response(['error' => 'Método no permitido'], 405);

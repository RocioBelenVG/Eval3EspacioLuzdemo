<?php
declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

/**
 * Datos de prueba SINTÉTICOS de EspacioLuz Demo: 12 meses de operación de un centro
 * terapéutico (atenciones, pacientes, fichas, arriendo de boxes, boletas, facturas,
 * notas de crédito y pagos). Ningún dato corresponde a una persona real: los nombres
 * se combinan al azar, los RUT se generan con dígito verificador válido en rangos
 * ficticios y los correos usan el dominio reservado example.com.
 *
 *   vendor/bin/phinx seed:run -c phinx.php -s DatosSinteticos
 *
 * - Es determinista (misma semilla → mismos datos) y relativo a la fecha de carga:
 *   la historia cubre los 12 meses anteriores y la agenda, las 3 semanas siguientes.
 * - Reemplaza TODOS los datos de negocio: solo corre en una base cuyo nombre contiene
 *   «demo» o «test», o con SEED_PERMITIDO=1 (nunca en la base real).
 * - Desactiva la auditoría durante la carga (@app_auditoria = 0): el registro de
 *   auditoría parte vacío y refleja solo el uso real del sistema.
 */
final class DatosSinteticos extends AbstractSeed
{
    private const SEMILLA = 20261122;

    private PDO $pdo;
    private string $hoy;
    /** @var array<int, array> */
    private array $profes = [];
    /** @var array<int, array> */
    private array $pacientes = [];
    /** @var array<string, array> */
    private array $boxes = [];
    /** @var array<string, int> folio siguiente por "empresa:tipo" */
    private array $folios = [];
    private array $usadosRut = [];
    private array $feriados = [];
    private int $usuarioAdmin = 0;

    private const NOMBRES_F = ['Antonia', 'Sofía', 'Isidora', 'Florencia', 'Agustina', 'Emilia', 'Josefa', 'Martina', 'Trinidad', 'Catalina',
        'Fernanda', 'Javiera', 'Constanza', 'Camila', 'Valentina', 'Francisca', 'Ignacia', 'Amanda', 'Maite', 'Renata', 'Paz', 'Daniela',
        'Carolina', 'Claudia', 'Lorena', 'Patricia', 'Macarena', 'Rocío', 'Pía', 'Bárbara', 'Gabriela', 'Elisa', 'Julieta', 'Colomba'];
    private const NOMBRES_M = ['Mateo', 'Agustín', 'Benjamín', 'Vicente', 'Tomás', 'Joaquín', 'Lucas', 'Maximiliano', 'Martín', 'Gaspar',
        'Felipe', 'Diego', 'Matías', 'Sebastián', 'Cristóbal', 'Nicolás', 'Ignacio', 'Alonso', 'Bastián', 'Simón', 'Rodrigo', 'Andrés',
        'Gonzalo', 'Pablo', 'Francisco', 'Esteban', 'Emiliano', 'Facundo', 'León', 'Julián', 'Renato', 'Damián'];
    private const APELLIDOS = ['González', 'Muñoz', 'Rojas', 'Díaz', 'Pérez', 'Soto', 'Contreras', 'Silva', 'Martínez', 'Sepúlveda',
        'Morales', 'Rodríguez', 'López', 'Fuentes', 'Hernández', 'Torres', 'Araya', 'Flores', 'Espinoza', 'Valenzuela', 'Castillo',
        'Tapia', 'Reyes', 'Gutiérrez', 'Castro', 'Pizarro', 'Álvarez', 'Vásquez', 'Sánchez', 'Fernández', 'Ramírez', 'Carrasco',
        'Gómez', 'Cortés', 'Herrera', 'Núñez', 'Jara', 'Vergara', 'Rivera', 'Figueroa', 'Riquelme', 'Bravo', 'Vera', 'Molina',
        'Campos', 'Sandoval', 'Olivares', 'Orellana', 'Salazar', 'Navarro', 'Cáceres', 'Medina', 'Garrido', 'Henríquez', 'Saavedra'];
    private const CALLES = ['Avenida Alemania', 'Colo Colo', 'Lautaro', 'Valdivia', 'Caupolicán', 'Almagro', 'Ercilla', 'Villagrán',
        'Avenida Ricardo Vicuña', 'Rengo', 'Avenida Sor Vicenta', 'Los Carrera', 'Tucapel', 'Galvarino', 'Avenida Las Industrias',
        'Pasaje Los Aromos', 'Pasaje Los Robles', 'Avenida Gabriela Mistral', 'Janequeo', 'Orompello'];
    /** Comunas de la Región del Biobío con peso (Los Ángeles concentra la demanda). */
    private const COMUNAS = ['Los Ángeles' => 62, 'Mulchén' => 7, 'Nacimiento' => 6, 'Cabrero' => 5, 'Laja' => 5, 'Santa Bárbara' => 4,
        'Yumbel' => 3, 'Negrete' => 3, 'Quilleco' => 2, 'Tucapel' => 2, 'Antuco' => 1];

    /** Profesionales sintéticos: [nombre, especialidad, socia, agenda_online, box habitual, título]. */
    private const PROFESIONALES = [
        ['Fernanda Alarcón Ríos', 'terapia-ocupacional', 1, 1, 'colibri-gimnasio', 'Terapeuta Ocupacional'],
        ['Javiera Montes Lagos', 'fonoaudiologia', 1, 1, 'loica', 'Fonoaudióloga'],
        ['Tomás Ibarra Quezada', 'psicologia', 0, 0, 'martin-pescador', 'Psicólogo clínico'],
        ['Camila Ortega Vidal', 'psicopedagogia', 0, 0, '7-colores', 'Psicopedagoga'],
        ['Diego Paredes Lillo', 'terapia-ocupacional', 0, 0, 'gimnasio-golondrina', 'Terapeuta Ocupacional'],
        ['Valeria Sanhueza Mora', 'psicologia', 0, 0, 'loica', 'Psicóloga infanto-juvenil'],
        ['Ignacio Fuentealba Cid', 'psiquiatria', 0, 0, 'martin-pescador', 'Médico psiquiatra'],
        ['Constanza Leal Parra', 'fonoaudiologia', 0, 0, '7-colores', 'Fonoaudióloga'],
        ['Matías Arriagada Toro', 'psicologia', 0, 0, 'martin-pescador', 'Psicólogo'],
        ['Daniela Venegas Rubio', 'terapia-ocupacional', 0, 0, 'gimnasio-golondrina', 'Terapeuta Ocupacional'],
    ];

    /** Servicios de las socias (exentos de IVA, como las prestaciones de salud). */
    private const SERVICIOS = [
        'terapia-ocupacional' => [['Sesión de terapia ocupacional', 35000], ['Evaluación de perfil sensorial', 70000], ['Pack 4 sesiones de terapia ocupacional', 140000]],
        'fonoaudiologia'      => [['Sesión de fonoaudiología', 32000], ['Evaluación fonoaudiológica', 60000], ['Pack 4 sesiones de fonoaudiología', 124000]],
    ];

    public function run(): void
    {
        $this->pdo = $this->getAdapter()->getConnection();
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->asegurarBaseDemo();
        mt_srand(self::SEMILLA);
        $this->hoy = (string) $this->pdo->query('SELECT CURDATE()')->fetchColumn();
        $this->pdo->exec("SET @app_auditoria = 0, @app_origen = 'seed'");

        $this->limpiar();
        $this->configuracion();
        $this->empresas();
        $this->usuarios();
        $this->profesionales();
        $this->clientes();
        $this->productos();
        $this->pacientes();
        $this->agenda();
        $this->cobrosPacientes();
        $this->facturasClientes();
        $this->notasCredito();
        $this->fichasYEvoluciones();
        $this->notasInternas();
        $this->cerrarCaf();

        $this->pdo->exec('SET @app_auditoria = NULL, @app_origen = NULL');
        $this->resumen();
    }

    // ------------------------------------------------------------------ guardas

    private function asegurarBaseDemo(): void
    {
        $bd = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        if (!preg_match('/demo|test/i', $bd) && getenv('SEED_PERMITIDO') !== '1') {
            throw new RuntimeException("Seeder bloqueado: la base «{$bd}» no parece de demostración ni de pruebas.");
        }
    }

    private function limpiar(): void
    {
        $tablas = ['auditoria', 'exportaciones', 'ia_consultas', 'rate_limits', 'evoluciones', 'paciente_informes', 'certificados_atencion',
            'fichas', 'pacientes_notas', 'clientes_notas', 'clientes_profesionales', 'notas_debito_items', 'notas_debito',
            'notas_credito_items', 'notas_credito', 'documento_pagos', 'boleta_items', 'reservas_movimientos', 'reservas', 'boletas',
            'dte_documento_det', 'dte_documento_ref', 'sii_envios', 'dte_documentos', 'caf_empresa', 'empresa_cert', 'folios_config',
            'folios_solicitudes', 'disponibilidad', 'productos', 'clientes', 'pacientes', 'profesionales', 'usuarios', 'empresas',
            'boxes_precios', 'configuracion'];
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tablas as $t) {
            $this->pdo->exec("DELETE FROM `$t`");
            $this->pdo->exec("ALTER TABLE `$t` AUTO_INCREMENT = 1");
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    // ------------------------------------------------------------------ utilidades

    private function azar(array $a): mixed
    {
        return $a[mt_rand(0, count($a) - 1)];
    }

    private function prob(float $p): bool
    {
        return mt_rand() / mt_getrandmax() < $p;
    }

    private function ponderado(array $pesos): string
    {
        $t = mt_rand(1, (int) array_sum($pesos));
        foreach ($pesos as $k => $w) {
            if (($t -= $w) <= 0) {
                return (string) $k;
            }
        }
        return (string) array_key_first($pesos);
    }

    private function rutDv(int $cuerpo): string
    {
        $s = 0;
        $m = 2;
        foreach (array_reverse(str_split((string) $cuerpo)) as $d) {
            $s += (int) $d * $m;
            $m = $m === 7 ? 2 : $m + 1;
        }
        $r = 11 - ($s % 11);
        return $r === 11 ? '0' : ($r === 10 ? 'K' : (string) $r);
    }

    /** RUT único formateado (19.036.123-3) en el rango pedido. */
    private function rut(int $min, int $max): string
    {
        do {
            $c = mt_rand($min, $max);
        } while (isset($this->usadosRut[$c]));
        $this->usadosRut[$c] = true;
        return number_format($c, 0, '', '.') . '-' . $this->rutDv($c);
    }

    private function telefono(): string
    {
        return '+56 9 ' . mt_rand(5000, 9899) . ' ' . str_pad((string) mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);
    }

    private function correo(string $nombre): string
    {
        $base = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $nombre) ?: 'usuario');
        $p = preg_split('/\s+/', preg_replace('/[^a-z ]/', '', $base) ?? '') ?: ['usuario'];
        return $p[0] . '.' . ($p[1] ?? 'demo') . mt_rand(1, 99) . '@example.com';
    }

    private function sumarDias(string $f, int $d): string
    {
        return date('Y-m-d', strtotime("$f $d days"));
    }

    private function insertar(string $tabla, array $fila): int
    {
        $cols = array_keys($fila);
        $sql = 'INSERT INTO `' . $tabla . '` (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $st = $this->pdo->prepare($sql);
        $st->execute(array_values($fila));
        return (int) $this->pdo->lastInsertId();
    }

    private function folio(int $empresa, int $tipo): int
    {
        $k = "$empresa:$tipo";
        $this->folios[$k] ??= 1;
        return $this->folios[$k]++;
    }

    // ------------------------------------------------------------------ maestros

    private function configuracion(): void
    {
        foreach (['box_cupo_max' => '2', 'factura_empresa_id' => '1', 'fonts' => '{"heading":"fraunces","body":"inter"}'] as $k => $v) {
            $this->insertar('configuracion', ['clave' => $k, 'valor' => $v]);
        }
        // Feriados del período anterior al calendario cargado (diciembre 2025).
        foreach ([['2025-12-08', 'Inmaculada Concepción', 0], ['2025-12-25', 'Navidad', 1]] as [$f, $n, $irr]) {
            $this->pdo->prepare("INSERT IGNORE INTO feriados (fecha, nombre, irrenunciable, se_trabaja, origen) VALUES (?,?,?,0,'legal')")->execute([$f, $n, $irr]);
        }
        foreach ($this->pdo->query('SELECT fecha FROM feriados WHERE se_trabaja = 0') as $r) {
            $this->feriados[$r['fecha']] = true;
        }
    }

    private function empresas(): void
    {
        $this->insertar('empresas', [
            'razon_social' => 'Espacio Luz Demo SpA', 'nombre_fantasia' => 'Espacio Luz (demo)', 'rut' => '76.' . '543.210-' . $this->rutDv(76543210),
            'giro' => 'Arriendo de consultas y boxes de atención terapéutica', 'actividad_economica' => '682000 Arriendo de inmuebles', 'acteco' => 682000,
            'sii_resolucion_numero' => 0, 'sii_resolucion_fecha' => '2026-01-01', 'direccion' => 'Avenida Demostración 100', 'comuna' => 'Los Ángeles',
            'ciudad' => 'Los Ángeles', 'email' => 'facturacion@example.com', 'telefono' => '+56 43 200 0000', 'tipo_documento' => 'factura', 'orden' => 1,
            'dte_certificados' => '34,56,61',
        ]);
        $this->insertar('empresas', [
            'razon_social' => 'Centro Terapéutico Luz Demo Ltda.', 'nombre_fantasia' => 'Centro Luz (demo)', 'rut' => '77.' . '654.321-' . $this->rutDv(77654321),
            'giro' => 'Servicios de terapia ocupacional y fonoaudiología', 'actividad_economica' => '869090 Otras actividades de atención de la salud', 'acteco' => 869090,
            'sii_resolucion_numero' => 0, 'sii_resolucion_fecha' => '2026-01-01', 'direccion' => 'Avenida Demostración 100', 'comuna' => 'Los Ángeles',
            'ciudad' => 'Los Ángeles', 'email' => 'boletas@example.com', 'telefono' => '+56 43 200 0001', 'tipo_documento' => 'boleta', 'orden' => 2,
            'dte_certificados' => '41,61',
        ]);
    }

    private function usuarios(): void
    {
        $clave = getenv('DEMO_PASSWORD') ?: '';
        if (strlen($clave) < 10) {
            throw new RuntimeException('Define DEMO_PASSWORD (10 caracteres o más) en el .env antes de cargar los datos de prueba.');
        }
        $hash = password_hash($clave, PASSWORD_BCRYPT);
        $cuentas = [
            ['Administración Demo', 'admin@espacioluzdemo.cl', 'admin'],
            ['Recepción Demo', 'recepcion@espacioluzdemo.cl', 'editor'],
            ['Fernanda Alarcón Ríos', 'clinica@espacioluzdemo.cl', 'pacientes'],
            ['Gerencia Demo', 'gerencia@espacioluzdemo.cl', 'gerencia'],
        ];
        foreach ($cuentas as [$n, $e, $r]) {
            $id = $this->insertar('usuarios', ['nombre' => $n, 'email' => $e, 'password_hash' => $hash, 'rol' => $r, 'activo' => 1,
                'creado_en' => $this->sumarDias($this->hoy, -380) . ' 09:00:00']);
            if ($r === 'admin') {
                $this->usuarioAdmin = $id;
            }
        }
    }

    private function profesionales(): void
    {
        foreach ($this->pdo->query('SELECT slug, nombre, tarifas FROM boxes WHERE eliminado_at IS NULL AND activo = 1') as $b) {
            $t = json_decode((string) $b['tarifas'], true) ?: [];
            $this->boxes[$b['slug']] = ['nombre' => $b['nombre'], 'hora' => (int) ($t[0]['precio'] ?? 8000)];
        }
        $usuarioClinica = (int) $this->pdo->query("SELECT id FROM usuarios WHERE rol = 'pacientes' LIMIT 1")->fetchColumn();
        foreach (self::PROFESIONALES as $i => [$nombre, $esp, $socia, $online, $box, $titulo]) {
            $slug = preg_replace('/[^a-z]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $nombre) ?: 'prof')) ?? 'prof';
            $id = $this->insertar('profesionales', [
                'nombre' => $nombre, 'rut' => $this->rut(12000000, 19999999), 'slug' => trim($slug, '-'), 'especialidad_slug' => $esp,
                'titulo' => $titulo, 'descripcion' => 'Profesional de demostración del área de ' . str_replace('-', ' ', $esp) . '.',
                'enfoque' => 'Atención individual con enfoque familiar.', 'duracion_texto' => '45-60 minutos',
                'empresa_id' => $socia ? 2 : null, 'user_id' => $i === 0 ? $usuarioClinica : null, 'activo' => 1, 'publico' => 1,
                'orden' => $i + 1, 'agenda_online' => $online, 'socia' => $socia, 'box_slug' => $box,
            ]);
            $this->profes[$id] = ['id' => $id, 'nombre' => $nombre, 'esp' => $esp, 'socia' => (bool) $socia, 'box' => $box];
            if ($online) {
                foreach ([1, 2, 3, 4, 5] as $dia) {
                    $this->insertar('disponibilidad', ['profesional_id' => $id, 'dia_semana' => $dia, 'hora_inicio' => '09:00:00', 'hora_fin' => '18:00:00', 'activo' => 1]);
                }
            }
        }
    }

    private function clientes(): void
    {
        // Cada profesional externo arrienda boxes y recibe una factura mensual: es un cliente.
        foreach ($this->profes as $p) {
            if ($p['socia']) {
                continue;
            }
            $empresa = $this->prob(0.3);
            $id = $this->insertar('clientes', [
                'rut' => $empresa ? $this->rut(76000000, 77999999) : $this->rut(12000000, 19999999),
                'razon_social' => $empresa ? 'Consulta ' . explode(' ', $p['nombre'])[1] . ' SpA' : $p['nombre'],
                'giro' => $empresa ? 'Servicios profesionales de salud' : 'Profesional independiente del área de la salud',
                'direccion' => $this->azar(self::CALLES) . ' ' . mt_rand(100, 1999), 'region' => 'Biobío', 'comuna' => 'Los Ángeles',
                'ciudad' => 'Los Ángeles', 'email' => $this->correo($p['nombre']), 'telefono' => $this->telefono(), 'contacto' => $p['nombre'],
                'profesional_id' => $p['id'], 'box_slug' => $p['box'], 'tarifa' => 'Por hora', 'medio_pago' => 'transferencia', 'activo' => 1,
                'creado_por' => $this->usuarioAdmin, 'creado_en' => $this->sumarDias($this->hoy, -370) . ' 10:00:00',
            ]);
            $this->insertar('clientes_profesionales', ['cliente_id' => $id, 'profesional_id' => $p['id']]);
            $this->profes[$p['id']]['cliente_id'] = $id;
        }
    }

    private function productos(): void
    {
        $orden = 0;
        foreach ($this->profes as $p) {
            if (!$p['socia']) {
                continue;
            }
            foreach (self::SERVICIOS[$p['esp']] as [$nombre, $precio]) {
                $this->insertar('productos', ['empresa_id' => 2, 'profesional_id' => $p['id'], 'nombre' => $nombre, 'precio_neto' => $precio,
                    'afecto' => 0, 'activo' => 1, 'orden' => ++$orden]);
            }
        }
    }

    private function pacientes(): void
    {
        $previsiones = array_column($this->pdo->query('SELECT nombre FROM previsiones WHERE activo = 1')->fetchAll(), 'nombre');
        $inicio = strtotime($this->sumarDias($this->hoy, -365));
        for ($i = 0; $i < 520; $i++) {
            $mujer = $this->prob(0.48);
            $nombre = $this->azar($mujer ? self::NOMBRES_F : self::NOMBRES_M) . ' ' . $this->azar(self::APELLIDOS) . ' ' . $this->azar(self::APELLIDOS);
            // Población infantil mayoritaria (terapia ocupacional y fonoaudiología).
            $edad = $this->prob(0.72) ? mt_rand(2, 14) : mt_rand(15, 70);
            $fnac = date('Y-m-d', strtotime($this->hoy . " -$edad years -" . mt_rand(0, 364) . ' days'));
            $menor = $edad < 18;
            $creado = date('Y-m-d H:i:s', $inicio + (int) (pow(mt_rand() / mt_getrandmax(), 0.8) * 365 * 86400) + mt_rand(8, 19) * 3600);
            $apoderado = $menor ? $this->azar($this->prob(0.7) ? self::NOMBRES_F : self::NOMBRES_M) . ' ' . explode(' ', $nombre)[1] . ' ' . $this->azar(self::APELLIDOS) : null;
            $rutPaciente = $this->rut($menor ? 22000000 : 8000000, $menor ? 27500000 : 21000000);
            $rutApoderado = $menor ? $this->rut(9000000, 20000000) : null;
            $id = $this->insertar('pacientes', [
                'nombre' => $nombre, 'rut' => $rutPaciente, 'documento_tipo' => 'rut',
                'email' => $this->correo($apoderado ?? $nombre), 'telefono' => $this->telefono(), 'fecha_nacimiento' => $fnac,
                'sexo' => $mujer ? 'mujer' : 'hombre', 'direccion' => $this->azar(self::CALLES) . ' ' . mt_rand(10, 2999), 'region' => 'Biobío',
                'comuna' => $this->ponderado(self::COMUNAS), 'apoderado' => $apoderado, 'apoderado_rut' => $rutApoderado,
                'apoderado_telefono' => $menor ? $this->telefono() : null, 'prevision' => $this->azar($previsiones ?: ['FONASA']),
                'consentimiento' => 1, 'consentimiento_fecha' => $creado, 'activo' => $this->prob(0.97) ? 1 : 0, 'creado_en' => $creado,
            ]);
            // Duración del tratamiento: la mayoría entre 2 y 7 meses; algunos, procesos largos. Después reciben el alta.
            $dur = $this->prob(0.2) ? mt_rand(240, 400) : mt_rand(45, 210);
            $this->pacientes[$id] = ['id' => $id, 'nombre' => $nombre, 'menor' => $menor, 'apoderado' => $apoderado, 'creado' => substr($creado, 0, 10),
                'rut' => $rutPaciente, 'apoderado_rut' => $rutApoderado, 'alta' => date('Y-m-d', strtotime(substr($creado, 0, 10) . " +$dur days"))];
        }
    }

    // ------------------------------------------------------------------ agenda

    /** Factor de demanda por mes: vacaciones de verano bajas, peak marzo–junio y crecimiento anual. */
    private function demanda(string $fecha, int $indiceDia, int $totalDias): float
    {
        $mes = (int) substr($fecha, 5, 2);
        $estacional = [1 => 0.70, 2 => 0.55, 3 => 1.10, 4 => 1.15, 5 => 1.15, 6 => 1.10, 7 => 0.85, 8 => 1.05, 9 => 0.95, 10 => 1.05, 11 => 1.05, 12 => 0.85][$mes];
        return $estacional * (0.82 + 0.30 * ($indiceDia / max(1, $totalDias)));
    }

    private function agenda(): void
    {
        $desde = $this->sumarDias($this->hoy, -365);
        $hasta = $this->sumarDias($this->hoy, 21);
        $totalDias = 386;
        // Bloques semanales fijos de arriendo por profesional externo: [día (1=lun), hora inicio, horas].
        $bloques = [];
        foreach ($this->profes as $p) {
            if ($p['socia']) {
                continue;
            }
            $n = mt_rand(2, 3);
            for ($k = 0; $k < $n; $k++) {
                $bloques[$p['id']][] = [mt_rand(1, 6), mt_rand(8, 16), mt_rand(2, 4)];
            }
        }
        $socias = array_values(array_filter($this->profes, fn ($p) => $p['socia']));
        $pacientesPorSocia = [];
        foreach ($this->pacientes as $pa) {
            $pacientesPorSocia[$socias[$pa['id'] % count($socias)]['id']][] = $pa;
        }

        $ocupado = [];  // "box|fecha|hora" => n  ;  "prof|fecha|hora" => true
        $ins = $this->pdo->prepare('INSERT INTO reservas (publico_id, box_slug, profesional_id, paciente_id, modalidad_label, modalidad_sub, tipo_atencion,
            precio_clp, fecha, hora, duracion_min, nombre, email, telefono, estado, origen, notas, creado_en)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $this->pdo->beginTransaction();
        for ($d = 0; $d < $totalDias; $d++) {
            $fecha = $this->sumarDias($desde, $d);
            $dow = (int) date('N', strtotime($fecha));
            if ($dow === 7 || isset($this->feriados[$fecha])) {
                continue;
            }
            $f = $this->demanda($fecha, $d, $totalDias);
            $pasado = $fecha < $this->hoy;
            $esHoy = $fecha === $this->hoy;

            // 1) Arriendos de box de profesionales externos.
            foreach ($bloques as $pid => $lista) {
                foreach ($lista as [$dia, $h0, $horas]) {
                    if ($dia !== $dow || !$this->prob(min(0.97, 0.80 * $f))) {
                        continue;
                    }
                    $box = $this->profes[$pid]['box'];
                    for ($h = $h0; $h < min(20, $h0 + $horas); $h++) {
                        $hora = sprintf('%02d:00:00', $h);
                        if (($ocupado["$box|$fecha|$hora"] ?? 0) >= 2 || isset($ocupado["$pid|$fecha|$hora"])) {
                            continue;
                        }
                        $estado = $pasado ? ($this->prob(0.93) ? 'realizada' : 'cancelada') : ($this->prob(0.85) ? 'confirmada' : 'pendiente');
                        $ins->execute([bin2hex(random_bytes(8)), $box, $pid, null, 'Arriendo de box', 'Por hora', null, $this->boxes[$box]['hora'],
                            $fecha, $hora, 60, $this->profes[$pid]['nombre'], '', '', $estado, 'panel', null,
                            $this->sumarDias($fecha, -mt_rand(3, 30)) . ' ' . sprintf('%02d:%02d:00', mt_rand(8, 20), mt_rand(0, 59))]);
                        $ocupado["$box|$fecha|$hora"] = ($ocupado["$box|$fecha|$hora"] ?? 0) + 1;
                        $ocupado["$pid|$fecha|$hora"] = true;
                    }
                }
            }

            // 2) Sesiones de las socias con pacientes (origen web o panel).
            foreach ($socias as $s) {
                $n = (int) round(mt_rand(4, 7) * $f);
                $horas = range(9, 18);
                shuffle($horas);
                $lista = array_values(array_filter($pacientesPorSocia[$s['id']] ?? [], fn ($pa) => $pa['creado'] <= $fecha && $fecha <= $pa['alta']));
                if ($lista === []) {
                    continue;
                }
                foreach (array_slice($horas, 0, $n) as $h) {
                    $hora = sprintf('%02d:00:00', $h);
                    $box = $s['box'];
                    if (($ocupado["$box|$fecha|$hora"] ?? 0) >= 2 || isset($ocupado["{$s['id']}|$fecha|$hora"])) {
                        continue;
                    }
                    $pa = $this->azar($lista);
                    $tipo = $this->ponderado(['intervencion' => 70, 'evaluacion' => 15, 'orientacion_familiar' => 10, 'etapas_vida' => 5]);
                    $serv = self::SERVICIOS[$s['esp']][$tipo === 'evaluacion' ? 1 : 0];
                    $precio = $tipo === 'evaluacion' ? (int) ($serv[1] / 2) : $serv[1];
                    if ($pasado) {
                        $estado = $this->ponderado(['realizada' => 82, 'cancelada' => 11, 'no_show' => 7]);
                    } elseif ($esHoy) {
                        $estado = 'confirmada';
                    } else {
                        $estado = $this->prob(0.6) ? 'confirmada' : 'pendiente';
                    }
                    $origen = $this->prob(0.35) ? 'web' : 'panel';
                    $creado = $this->sumarDias($fecha, -mt_rand(1, 21));
                    $creado = max($creado, $pa['creado']);
                    $ins->execute([bin2hex(random_bytes(8)), $box, $s['id'], $pa['id'], 'Sesión ' . ($s['esp'] === 'fonoaudiologia' ? 'de fonoaudiología' : 'de terapia ocupacional'),
                        null, $tipo, $precio, $fecha, $hora, mt_rand(0, 4) === 0 ? 45 : 60, $pa['nombre'], $this->correo($pa['apoderado'] ?? $pa['nombre']),
                        $this->telefono(), $estado, $origen, null, $creado . ' ' . sprintf('%02d:%02d:00', mt_rand(8, 21), mt_rand(0, 59))]);
                    $ocupado["$box|$fecha|$hora"] = ($ocupado["$box|$fecha|$hora"] ?? 0) + 1;
                    $ocupado["{$s['id']}|$fecha|$hora"] = true;
                }
            }
            if ($d % 40 === 39) {
                $this->pdo->commit();
                $this->pdo->beginTransaction();
            }
        }
        $this->pdo->commit();
        // Movimientos: registra las cancelaciones de la historia.
        $this->pdo->exec("INSERT INTO reservas_movimientos (reserva_id, accion, profesional_origen_id, usuario_nombre, detalle, creado_en)
            SELECT id, 'cancelacion', profesional_id, 'Recepción Demo', 'Cancelación individual', TIMESTAMP(fecha, '08:00:00') - INTERVAL 1 DAY
              FROM reservas WHERE estado = 'cancelada'");
    }

    // ------------------------------------------------------------------ cobros

    /** Boletas exentas (41) por las sesiones realizadas de pacientes. */
    private function cobrosPacientes(): void
    {
        $rows = $this->pdo->query("SELECT r.id, r.fecha, r.hora, r.precio_clp, r.paciente_id, r.profesional_id, r.modalidad_label, r.tipo_atencion
                                     FROM reservas r WHERE r.paciente_id IS NOT NULL AND r.estado = 'realizada' ORDER BY r.fecha, r.hora")->fetchAll();
        $limiteSinCobro = $this->sumarDias($this->hoy, -10);
        $this->pdo->beginTransaction();
        $n = 0;
        foreach ($rows as $r) {
            // Las sesiones recientes a veces quedan por cobrar (para el indicador de cuentas por cobrar).
            if ($r['fecha'] >= $limiteSinCobro ? $this->prob(0.35) : $this->prob(0.04)) {
                continue;
            }
            $pa = $this->pacientes[(int) $r['paciente_id']];
            $pr = $this->profes[(int) $r['profesional_id']];
            $total = (int) $r['precio_clp'];
            $folio = $this->folio(2, 41);
            $emision = $r['fecha'] . ' ' . date('H:i:s', strtotime($r['hora'] . ' +55 minutes'));
            $medio = $this->ponderado(['transferencia' => 55, 'tarjeta' => 30, 'efectivo' => 15]);
            $pagado = $this->prob(0.97);
            $esTutor = $pa['menor'] && $pa['apoderado'];
            $bid = $this->insertar('boletas', [
                'folio' => $folio, 'tipo' => 'boleta', 'serie' => 'sii', 'empresa_id' => 2, 'emisor_usuario_id' => $this->usuarioAdmin,
                'emisor_nombre' => 'Centro Luz (demo)', 'receptor_nombre' => $esTutor ? $pa['apoderado'] : $pa['nombre'],
                'receptor_rut' => $esTutor ? $pa['apoderado_rut'] : $pa['rut'],
                'paciente_id' => $pa['id'], 'receptor_tipo' => $esTutor ? 'tutor' : 'paciente', 'reserva_id' => $r['id'],
                'profesional_id' => $pr['id'], 'profesional_nombre' => $pr['nombre'], 'fecha_emision' => $emision, 'medio_pago' => $medio,
                'neto_clp' => 0, 'iva_clp' => 0, 'exento_clp' => $total, 'total_clp' => $total,
                'glosa' => 'Atención del ' . date('d-m-Y', strtotime($r['fecha'])),
                'pago_estado' => $pagado ? 'pagado' : 'pendiente', 'pagado_clp' => $pagado ? $total : 0, 'pagado_en' => $pagado ? $emision : null,
                'sii_estado' => 'aceptada', 'sii_track_id' => 'DEMO-' . date('ymd', strtotime($r['fecha'])) . '-' . str_pad((string) $folio, 6, '0', STR_PAD_LEFT),
            ]);
            $this->insertar('boleta_items', ['boleta_id' => $bid, 'descripcion' => $r['modalidad_label'], 'cantidad' => 1, 'precio_unit_clp' => $total,
                'afecto' => 0, 'total_clp' => $total, 'profesional_id' => $pr['id']]);
            if ($pagado) {
                $this->insertar('documento_pagos', ['documento_id' => $bid, 'fecha' => $r['fecha'], 'monto' => $total, 'medio' => $medio,
                    'usuario_nombre' => 'Recepción Demo', 'creado_en' => $emision]);
            }
            $this->pdo->prepare('UPDATE reservas SET boleta_id = ?, boleta_neto = ?, boleta_afecto = 0 WHERE id = ?')->execute([$bid, $total, $r['id']]);
            $this->dte($bid, 2, 41, $folio, $emision, $esTutor ? $pa['apoderado_rut'] : $pa['rut'], $esTutor ? $pa['apoderado'] : $pa['nombre'], $pa['id'], 0, $total, [[$r['modalidad_label'], 1, $total]]);
            if (++$n % 300 === 0) {
                $this->pdo->commit();
                $this->pdo->beginTransaction();
            }
        }
        $this->pdo->commit();
    }

    /** Facturas exentas (34) mensuales a cada cliente por sus horas de box del mes anterior. */
    private function facturasClientes(): void
    {
        $meses = $this->pdo->query("SELECT DISTINCT DATE_FORMAT(fecha, '%Y-%m') m FROM reservas WHERE fecha < DATE_FORMAT(CURDATE(), '%Y-%m-01') ORDER BY m")->fetchAll();
        $clientes = $this->pdo->query('SELECT id, rut, razon_social, giro, direccion, comuna, profesional_id FROM clientes')->fetchAll();
        $this->pdo->beginTransaction();
        foreach ($meses as ['m' => $mes]) {
            foreach ($clientes as $c) {
                $st = $this->pdo->prepare("SELECT id, precio_clp FROM reservas WHERE profesional_id = ? AND paciente_id IS NULL AND estado = 'realizada'
                                             AND DATE_FORMAT(fecha, '%Y-%m') = ? AND factura_id IS NULL");
                $st->execute([$c['profesional_id'], $mes]);
                $horas = $st->fetchAll();
                if (!$horas) {
                    continue;
                }
                $total = array_sum(array_map(fn ($h) => (int) $h['precio_clp'], $horas));
                $emision = date('Y-m-d', strtotime("$mes-01 +1 month"));
                while ((int) date('N', strtotime($emision)) >= 6) {
                    $emision = $this->sumarDias($emision, 1);
                }
                $folio = $this->folio(1, 34);
                $vence = $this->sumarDias($emision, 30);
                // Comportamiento de pago: la mayoría paga en 5–35 días; un cliente paga tarde; los últimos meses quedan abiertos.
                $diasPago = $c['id'] % 4 === 0 ? mt_rand(25, 60) : mt_rand(3, 35);
                $fechaPago = $this->sumarDias($emision, $diasPago);
                $pagado = $fechaPago <= $this->hoy;
                $parcial = !$pagado && $this->prob(0.4) && $this->sumarDias($emision, 10) <= $this->hoy;
                $pagadoClp = $pagado ? $total : ($parcial ? (int) round($total * 0.5) : 0);
                $fid = $this->insertar('boletas', [
                    'folio' => $folio, 'tipo' => 'factura', 'serie' => 'sii', 'empresa_id' => 1, 'emisor_usuario_id' => $this->usuarioAdmin,
                    'emisor_nombre' => 'Espacio Luz (demo)', 'receptor_nombre' => $c['razon_social'], 'receptor_rut' => $c['rut'], 'cliente_id' => $c['id'],
                    'receptor_tipo' => 'cliente', 'receptor_giro' => $c['giro'], 'receptor_direccion' => $c['direccion'], 'receptor_comuna' => $c['comuna'],
                    'fecha_emision' => "$emision 10:00:00", 'medio_pago' => 'transferencia', 'neto_clp' => 0, 'iva_clp' => 0, 'exento_clp' => $total,
                    'total_clp' => $total, 'glosa' => 'Arriendo de box por hora · ' . $mes,
                    'pago_estado' => $pagado ? 'pagado' : ($parcial ? 'parcial' : 'pendiente'), 'pagado_clp' => $pagadoClp,
                    'vence_en' => $vence, 'pagado_en' => $pagado ? "$fechaPago 12:00:00" : null,
                    'sii_estado' => 'aceptada', 'sii_track_id' => 'DEMO-' . date('ymd', strtotime($emision)) . '-' . str_pad((string) $folio, 6, '0', STR_PAD_LEFT),
                ]);
                $this->insertar('boleta_items', ['boleta_id' => $fid, 'descripcion' => 'Arriendo de box por hora (' . count($horas) . ' h) · ' . $mes,
                    'cantidad' => count($horas), 'precio_unit_clp' => (int) round($total / count($horas)), 'afecto' => 0, 'total_clp' => $total]);
                if ($pagadoClp > 0) {
                    $this->insertar('documento_pagos', ['documento_id' => $fid, 'fecha' => $pagado ? $fechaPago : $this->sumarDias($emision, 10),
                        'monto' => $pagadoClp, 'medio' => 'transferencia', 'referencia' => 'Transferencia demo', 'usuario_nombre' => 'Administración Demo']);
                }
                $ids = array_column($horas, 'id');
                $this->pdo->prepare('UPDATE reservas SET factura_id = ?, cobro_neto = precio_clp, cobro_afecto = 0 WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')')->execute([$fid]);
                $this->dte($fid, 1, 34, $folio, "$emision 10:00:00", $c['rut'], $c['razon_social'], null, 0, $total,
                    [['Arriendo de box por hora · ' . $mes, count($horas), (int) round($total / count($horas))]]);
            }
        }
        $this->pdo->commit();
    }

    /** Notas de crédito (61): devoluciones parciales de algunas boletas. */
    private function notasCredito(): void
    {
        $bol = $this->pdo->query("SELECT id, folio, fecha_emision, total_clp, empresa_id, receptor_nombre, receptor_rut, paciente_id FROM boletas
                                   WHERE tipo = 'boleta' AND pago_estado = 'pagado' ORDER BY RAND(" . self::SEMILLA . ') LIMIT 18')->fetchAll();
        foreach ($bol as $b) {
            $monto = (int) round((int) $b['total_clp'] * $this->azar([0.5, 1.0]));
            $fecha = date('Y-m-d H:i:s', strtotime($b['fecha_emision'] . ' +' . mt_rand(1, 6) . ' days'));
            if (substr($fecha, 0, 10) > $this->hoy) {
                continue;
            }
            $folio = $this->folio(2, 61);
            $nid = $this->insertar('notas_credito', ['documento_id' => $b['id'], 'empresa_id' => $b['empresa_id'], 'folio' => $folio, 'serie' => 'sii',
                'fecha_emision' => $fecha, 'motivo' => 'Devolución por sesión no realizada', 'accion' => $monto === (int) $b['total_clp'] ? 'anulacion' : 'devolucion',
                'codigo_ref' => $monto === (int) $b['total_clp'] ? 1 : 3, 'neto_clp' => 0, 'iva_clp' => 0, 'exento_clp' => $monto, 'total_clp' => $monto,
                'emisor_nombre' => 'Centro Luz (demo)', 'usuario_nombre' => 'Administración Demo', 'sii_estado' => 'aceptada', 'sii_track_id' => 'DEMO-NC-' . $folio]);
            $this->insertar('notas_credito_items', ['nota_id' => $nid, 'descripcion' => 'Devolución de atención', 'cantidad' => 1, 'precio_unit_clp' => $monto,
                'afecto' => 0, 'total_clp' => $monto]);
            $this->insertar('documento_pagos', ['documento_id' => $b['id'], 'fecha' => substr($fecha, 0, 10), 'monto' => -$monto, 'medio' => 'transferencia',
                'referencia' => 'Devolución NC ' . $folio, 'usuario_nombre' => 'Administración Demo']);
            $this->pdo->prepare('UPDATE boletas SET nc_clp = nc_clp + ?, pagado_clp = pagado_clp - ? WHERE id = ?')->execute([$monto, $monto, $b['id']]);
            $did = $this->dte(null, 2, 61, $folio, $fecha, $b["receptor_rut"] ?: null, (string) $b['receptor_nombre'], $b['paciente_id'] ? (int) $b['paciente_id'] : null, 0, $monto,
                [['Devolución de atención', 1, $monto]], ['tipo_doc' => '41', 'folio' => (string) $b['folio'], 'fecha' => substr((string) $b['fecha_emision'], 0, 10),
                    'codigo' => $monto === (int) $b['total_clp'] ? 1 : 3, 'razon' => 'Devolución por sesión no realizada']);
            $this->pdo->prepare('UPDATE notas_credito SET dte_documento_id = ? WHERE id = ?')->execute([$did, $nid]);
        }
    }

    /** Documento DTE de demostración (dte_documentos + detalle), sin XML ni envío real. */
    private function dte(?int $boletaId, int $empresa, int $tipo, int $folio, string $fecha, ?string $rutR, string $razon, ?int $pacienteId,
        int $neto, int $exento, array $items, ?array $ref = null): int
    {
        static $ruts = [];
        $ruts[$empresa] ??= (string) $this->pdo->query("SELECT REPLACE(rut, '.', '') FROM empresas WHERE id = $empresa")->fetchColumn();
        $id = $this->insertar('dte_documentos', [
            'empresa_id' => $empresa, 'tipo_dte' => $tipo, 'folio' => $folio, 'ambiente' => 'produccion', 'rut_emisor' => $ruts[$empresa],
            'rut_receptor' => $rutR !== null ? str_replace('.', '', $rutR) : '66666666-6', 'razon_receptor' => $razon, 'paciente_id' => $pacienteId,
            'boleta_id' => $boletaId, 'fecha_emision' => substr($fecha, 0, 10), 'neto' => $neto, 'exento' => $exento, 'iva' => 0, 'total' => $neto + $exento,
            'estado' => 'ENVIADO', 'trackid' => 'DEMO-' . date('ymd', strtotime($fecha)) . '-' . str_pad((string) $folio, 6, '0', STR_PAD_LEFT),
            'datos_extra' => '{"demo":true,"forma_pago":"Contado"}', 'created_at' => $fecha, 'updated_at' => $fecha,
        ]);
        foreach ($items as [$desc, $cant, $precio]) {
            $this->insertar('dte_documento_det', ['documento_id' => $id, 'descripcion' => $desc, 'cantidad' => $cant, 'unidad' => 'UN',
                'precio_unitario' => $precio, 'exento' => 1, 'total_linea' => (int) round($cant * $precio)]);
        }
        if ($ref) {
            $this->insertar('dte_documento_ref', ['documento_id' => $id] + $ref);
        }
        return $id;
    }

    // ------------------------------------------------------------------ área clínica

    private function fichasYEvoluciones(): void
    {
        $notas = ['Se trabaja regulación sensorial con circuito motor; buena tolerancia a estímulos vestibulares.',
            'Paciente participa activamente. Se refuerzan rutinas de autonomía en la alimentación.',
            'Se observan avances en la articulación de fonemas trabajados. Se entregan ejercicios para el hogar.',
            'Sesión centrada en planificación motora; requiere apoyo verbal en tareas secuenciales.',
            'Se realiza orientación a la familia sobre estrategias de anticipación y rutinas visuales.',
            'Buena adherencia a las indicaciones. Se mantiene el plan de intervención.',
            'Se aplica pauta de evaluación; resultados se integran en el informe de avance.',
            'Se trabaja comprensión de instrucciones de dos pasos con apoyo visual.'];
        $rows = $this->pdo->query("SELECT r.id, r.fecha, r.paciente_id, r.profesional_id, p.especialidad_slug FROM reservas r
                                     JOIN profesionales p ON p.id = r.profesional_id
                                    WHERE r.paciente_id IS NOT NULL AND r.estado = 'realizada' ORDER BY r.fecha")->fetchAll();
        $fichas = [];
        $this->pdo->beginTransaction();
        foreach ($rows as $r) {
            $k = $r['paciente_id'] . '|' . $r['especialidad_slug'];
            if (!isset($fichas[$k])) {
                $fichas[$k] = $this->insertar('fichas', ['paciente_id' => $r['paciente_id'], 'especialidad_slug' => $r['especialidad_slug'],
                    'profesional_responsable_id' => $r['profesional_id'], 'motivo_consulta' => 'Derivación para evaluación y tratamiento (caso de demostración).',
                    'antecedentes' => 'Sin antecedentes relevantes registrados.', 'diagnostico' => 'Pendiente de evaluación integral.',
                    'objetivos' => 'Favorecer la autonomía y la participación en actividades cotidianas.', 'estado' => 'activa', 'abierta_en' => $r['fecha']]);
            }
            if ($this->prob(0.45)) {
                $this->insertar('evoluciones', ['ficha_id' => $fichas[$k], 'reserva_id' => $r['id'], 'profesional_id' => $r['profesional_id'],
                    'fecha' => $r['fecha'], 'nota_progreso' => $this->azar($notas), 'proxima_accion' => $this->prob(0.5) ? 'Continuar plan de intervención.' : null]);
            }
        }
        // Alta: se cierran las fichas de pacientes sin atenciones en los últimos 45 días ni agendadas a futuro.
        $this->pdo->exec("UPDATE fichas f
                            JOIN (SELECT paciente_id, MAX(fecha) AS ultima FROM reservas WHERE estado = 'realizada' AND paciente_id IS NOT NULL GROUP BY paciente_id) x
                              ON x.paciente_id = f.paciente_id
                             SET f.estado = 'cerrada', f.cerrada_en = GREATEST(x.ultima, f.abierta_en)
                           WHERE x.ultima < CURDATE() - INTERVAL 45 DAY
                             AND NOT EXISTS (SELECT 1 FROM reservas r WHERE r.paciente_id = f.paciente_id AND r.fecha >= CURDATE() AND r.estado IN ('pendiente','confirmada'))");
        $this->pdo->commit();
    }

    private function notasInternas(): void
    {
        $textos = ['Confirmar horario de la próxima semana por teléfono.', 'Solicitar copia del informe escolar.', 'Enviar recordatorio del pago pendiente.',
            'Coordinar evaluación de reingreso.', 'Actualizar datos de contacto del apoderado.'];
        foreach (array_slice(array_keys($this->pacientes), 0, 40) as $pid) {
            $this->insertar('pacientes_notas', ['paciente_id' => $pid, 'texto' => $this->azar($textos), 'completada' => $this->prob(0.6) ? 1 : 0,
                'creado_por' => $this->usuarioAdmin, 'creado_por_nombre' => 'Recepción Demo']);
        }
    }

    /** CAF demo de cada tipo usado, con el correlativo después del último folio cargado. */
    private function cerrarCaf(): void
    {
        foreach ([[1, 34], [1, 56], [1, 61], [2, 41], [2, 61]] as [$emp, $tipo]) {
            $siguiente = $this->folios["$emp:$tipo"] ?? 1;
            $hasta = max(1000, (int) (ceil($siguiente / 1000) * 1000));
            $this->insertar('caf_empresa', ['empresa_id' => $emp, 'tipo_dte' => $tipo, 'ambiente' => 'produccion', 'folio_desde' => 1, 'folio_hasta' => $hasta,
                'folio_actual' => $siguiente, 'caf_nombre' => "CAF DEMO $tipo (1-$hasta)", 'fecha_autorizacion' => $this->sumarDias($this->hoy, -20), 'activo' => 1]);
        }
    }

    private function resumen(): void
    {
        foreach (['pacientes', 'profesionales', 'clientes', 'reservas', 'boletas', 'notas_credito', 'documento_pagos', 'fichas', 'evoluciones', 'dte_documentos'] as $t) {
            $this->output->writeln(sprintf('  %-16s %6d', $t, (int) $this->pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn()));
        }
        $r = $this->pdo->query('SELECT MIN(fecha) a, MAX(fecha) b FROM reservas')->fetch();
        $this->output->writeln("  reservas desde {$r['a']} hasta {$r['b']}");
    }
}

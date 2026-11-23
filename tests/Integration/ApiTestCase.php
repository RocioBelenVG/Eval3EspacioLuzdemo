<?php
declare(strict_types=1);

namespace EspacioLuz\Tests\Integration;

use EspacioLuz\App;
use EspacioLuz\Container;
use EspacioLuz\Http\Request;
use EspacioLuz\Http\Response;
use EspacioLuz\Infrastructure\Database;
use EspacioLuz\Kernel;
use EspacioLuz\Tests\Support\FakeEmisorDte;
use EspacioLuz\Tests\Support\FakeIaClient;
use PHPUnit\Framework\TestCase;

/**
 * Pruebas de integración: recorren la API completa (router, JWT, roles, validación,
 * servicios, repositorios, vistas, disparadores y restricciones de MariaDB) sin pasar por HTTP.
 *
 * Cada prueba corre dentro de una transacción que se revierte en tearDown(), así que no
 * deja rastros en la base. Los usuarios de prueba se crean dentro de esa transacción con
 * una contraseña aleatoria distinta en cada ejecución (nunca se usan cuentas reales).
 */
abstract class ApiTestCase extends TestCase
{
    protected static ?Database $db = null;
    protected Kernel $kernel;
    protected Container $container;
    protected string $password;
    /** @var array<string, array{id: int, email: string}> */
    protected array $usuarios = [];
    protected int $profesionalClinico;
    protected int $profesionalExterno;
    protected int $pacienteAdulto;
    protected int $pacienteMenor;
    protected FakeEmisorDte $emisor;
    protected FakeIaClient $ia;
    protected ?array $sesionPanel = null;

    protected function setUp(): void
    {
        if (DB_NAME === '') {
            $this->markTestSkipped('Sin base de datos configurada (.env).');
        }
        self::$db ??= Database::connect(DB_HOST, (string) DB_PORT, DB_NAME, DB_USER, DB_PASS);
        self::$db->pdo->beginTransaction();
        $this->password = bin2hex(random_bytes(8));
        $this->crearFixtures();
        $this->armar(new FakeIaClient(), new FakeEmisorDte());
    }

    protected function tearDown(): void
    {
        if (self::$db?->pdo->inTransaction()) {
            self::$db->pdo->rollBack();
        }
    }

    /** (Re)construye el contenedor con los dobles indicados, sobre la misma conexión y transacción. */
    protected function armar(FakeIaClient $ia, FakeEmisorDte $emisor): void
    {
        $this->ia = $ia;
        $this->emisor = $emisor;
        $this->container = App::container(['db' => self::$db, 'ia' => $ia, 'emisor' => $emisor, 'sesion' => fn () => $this->sesionPanel]);
        $this->kernel = new Kernel($this->container);
    }

    private function crearFixtures(): void
    {
        $pdo = self::$db->pdo;
        $hash = password_hash($this->password, PASSWORD_BCRYPT, ['cost' => 4]);
        $sufijo = bin2hex(random_bytes(3));
        foreach (['admin' => 'Administrador', 'editor' => 'Recepcion', 'pacientes' => 'Clinico', 'gerencia' => 'Gerencia'] as $rol => $clave) {
            $email = strtolower("test.$clave.$sufijo@example.com");
            $pdo->prepare('INSERT INTO usuarios (nombre, email, password_hash, rol, activo) VALUES (?, ?, ?, ?, 1)')
                ->execute(["Prueba $clave", $email, $hash, $rol]);
            $this->usuarios[$clave] = ['id' => (int) $pdo->lastInsertId(), 'email' => $email];
        }
        $pdo->prepare("INSERT INTO profesionales (nombre, rut, slug, especialidad_slug, user_id, activo, publico, socia, box_slug)
                       VALUES ('Profesional Clínica Prueba', '11.111.111-1', ?, 'terapia-ocupacional', ?, 1, 0, 1, 'loica')")
            ->execute(["prueba-clinica-$sufijo", $this->usuarios['Clinico']['id']]);
        $this->profesionalClinico = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO profesionales (nombre, slug, especialidad_slug, activo, publico, socia, box_slug)
                       VALUES ('Profesional Externo Prueba', ?, 'psicologia', 1, 0, 0, 'martin-pescador')")->execute(["prueba-externo-$sufijo"]);
        $this->profesionalExterno = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO pacientes (nombre, rut, email, fecha_nacimiento, consentimiento, consentimiento_fecha, activo)
                       VALUES ('Paciente Adulto Prueba', ?, 'adulto.prueba@example.com', '1990-05-10', 1, NOW(), 1)")->execute([$this->rutUnico()]);
        $this->pacienteAdulto = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO pacientes (nombre, rut, fecha_nacimiento, apoderado, apoderado_rut, consentimiento, consentimiento_fecha, activo)
                       VALUES ('Paciente Menor Prueba', ?, ?, 'Apoderada Prueba', '12.345.678-5', 1, NOW(), 1)")
            ->execute([$this->rutUnico(), date('Y-m-d', strtotime('-7 years'))]);
        $this->pacienteMenor = (int) $pdo->lastInsertId();
    }

    /** RUT válido que no existe en la base (rango alto, fuera de los datos sintéticos). */
    protected function rutUnico(bool $formateado = true): string
    {
        do {
            $c = random_int(28000000, 29999999);
            $s = 0;
            $m = 2;
            foreach (array_reverse(str_split((string) $c)) as $d) {
                $s += (int) $d * $m;
                $m = $m === 7 ? 2 : $m + 1;
            }
            $dv = match ($r = 11 - ($s % 11)) { 11 => '0', 10 => 'K', default => (string) $r };
            $rut = $formateado ? number_format($c, 0, '', '.') . "-$dv" : "$c-$dv";
            $existe = self::$db->pdo->query('SELECT 1 FROM pacientes WHERE rut = ' . self::$db->pdo->quote(number_format($c, 0, '', '.') . "-$dv"))->fetchColumn();
        } while ($existe);
        return $rut;
    }

    /** Día hábil (lunes a viernes, sin feriado) a partir de hoy + $dias. */
    protected function diaHabil(int $dias): string
    {
        $t = strtotime("today $dias days");
        while (true) {
            $f = date('Y-m-d', $t);
            $feriado = self::$db->pdo->query("SELECT 1 FROM feriados WHERE fecha = '$f' AND se_trabaja = 0")->fetchColumn();
            if ((int) date('N', $t) <= 5 && !$feriado) {
                return $f;
            }
            $t += $dias >= 0 ? 86400 : -86400;
        }
    }

    /** Inserta directamente una atención (para preparar estados pasados). */
    protected function atencion(string $fecha, string $hora, string $estado, ?int $paciente, ?int $profesional = null, string $box = 'loica', int $precio = 35000): int
    {
        self::$db->pdo->prepare("INSERT INTO reservas (publico_id, box_slug, profesional_id, paciente_id, modalidad_label, precio_clp, fecha, hora, duracion_min, nombre, email, telefono, estado, origen)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 60, 'Prueba', '', '', ?, 'panel')")
            ->execute([bin2hex(random_bytes(8)), $box, $profesional ?? $this->profesionalClinico, $paciente, $paciente ? 'Sesión de terapia ocupacional' : 'Arriendo de box',
                $precio, $fecha, $hora, $estado]);
        return (int) self::$db->pdo->lastInsertId();
    }

    protected function request(string $method, string $path, array $body = [], array $query = [], ?string $token = null, array $headers = []): Response
    {
        $r = Request::create($method, $path, $body, $query, $token);
        if ($headers) {
            $r = new Request($r->method, $r->path, $r->query, $headers + $r->headers, $r->rawBody, $r->ip);
        }
        return $this->kernel->handle($r);
    }

    /** @var array<string, string> */
    private array $tokens = [];

    protected function token(string $rol): string
    {
        if (!isset($this->tokens[$rol])) {
            $r = $this->request('POST', '/auth/login', ['email' => $this->usuarios[$rol]['email'], 'password' => $this->password]);
            $this->assertSame(200, $r->status, $r->body);
            $this->tokens[$rol] = $r->data()['token'];
        }
        return $this->tokens[$rol];
    }

    protected function assertError(Response $r, int $status, string $code): void
    {
        $this->assertSame($status, $r->status, $r->body);
        $this->assertSame($code, $r->data()['error']['code'] ?? null, $r->body);
        $this->assertNotEmpty($r->data()['error']['request_id'] ?? null);
    }
}

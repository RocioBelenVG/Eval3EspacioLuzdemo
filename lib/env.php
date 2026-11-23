<?php
/**
 * Carga de variables de entorno desde el archivo .env (no versionado).
 *
 * Las variables reales del proceso (Docker, Apache SetEnv, CI) tienen prioridad:
 * el .env solo completa las que faltan. Así el mismo código funciona en el
 * hosting (con .env) y en contenedores (con variables de compose).
 */

declare(strict_types=1);

if (!function_exists('env_cargar')) {
    function env_cargar(string $archivo): void
    {
        if (!is_file($archivo) || !is_readable($archivo)) {
            return;
        }
        foreach (file($archivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linea) {
            $linea = trim($linea);
            if ($linea === '' || $linea[0] === '#' || !str_contains($linea, '=')) {
                continue;
            }
            [$clave, $valor] = array_map('trim', explode('=', $linea, 2));
            if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $clave)) {
                continue;
            }
            // Comillas opcionales alrededor del valor.
            if (strlen($valor) >= 2 && ($valor[0] === '"' || $valor[0] === "'") && substr($valor, -1) === $valor[0]) {
                $valor = substr($valor, 1, -1);
            }
            if (getenv($clave) === false) {
                putenv($clave . '=' . $valor);
                $_ENV[$clave] = $valor;
            }
        }
    }

    /** Valor de una variable de entorno, o $defecto si no existe o está vacía. */
    function env(string $clave, ?string $defecto = null): ?string
    {
        $v = getenv($clave);
        return ($v === false || $v === '') ? $defecto : $v;
    }
}

env_cargar(dirname(__DIR__) . '/.env');

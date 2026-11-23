<?php
/**
 * RUT chileno: limpieza, validación (dígito verificador, módulo 11) y formato.
 * Usado por Profesionales, boletas y certificados de atención.
 */
declare(strict_types=1);

/** "19.036.123-3" -> "190361233" (solo dígitos y K mayúscula). */
function rutLimpio(?string $rut): string
{
    return strtoupper(preg_replace('/[^0-9kK]/', '', (string) $rut));
}

/** Dígito verificador correcto para un cuerpo numérico. */
function rutDv(string $cuerpo): string
{
    $suma = 0;
    $mult = 2;
    for ($i = strlen($cuerpo) - 1; $i >= 0; $i--) {
        $suma += (int) $cuerpo[$i] * $mult;
        $mult = $mult === 7 ? 2 : $mult + 1;
    }
    $r = 11 - ($suma % 11);
    return $r === 11 ? '0' : ($r === 10 ? 'K' : (string) $r);
}

/** true si el RUT tiene formato y dígito verificador válidos. */
function rutValido(?string $rut): bool
{
    $l = rutLimpio($rut);
    if (!preg_match('/^(\d{7,8})([0-9K])$/', $l, $m)) {
        return false;
    }
    return rutDv($m[1]) === $m[2];
}

/** Formatea un RUT chileno: "190361233" -> "19.036.123-3". Si no parece RUT, lo deja igual. */
if (!function_exists('formatoRut')) {
    function formatoRut(?string $rut): string
    {
        $rut = trim((string) $rut);
        $limpio = rutLimpio($rut);
        if (strlen($limpio) < 2 || !preg_match('/^\d+[0-9K]$/', $limpio)) {
            return $rut;
        }
        return number_format((int) substr($limpio, 0, -1), 0, ',', '.') . '-' . substr($limpio, -1);
    }
}

<?php
declare(strict_types=1);

namespace EspacioLuz\Service;

use EspacioLuz\Exception\ApiException;
use EspacioLuz\Ia\IaClient;
use EspacioLuz\Ia\IaException;
use EspacioLuz\Repository\AuditoriaRepository;
use EspacioLuz\Security\AuthUser;
use EspacioLuz\Security\RateLimiter;
use EspacioLuz\Validation\Validator;

/**
 * CU-08 · Resumen ejecutivo con IA: el modelo de lenguaje recibe SOLO indicadores
 * agregados del período (nunca nombres, RUT ni datos clínicos: Ley 19.628 y 20.584) y
 * devuelve un resumen, hallazgos y recomendaciones para la gerencia del centro.
 *
 * Controles: entrada validada, rango máximo de un año, límite de 10 consultas por minuto
 * por usuario, tiempo máximo de espera (AI_TIMEOUT), respuesta del modelo validada
 * contra un esquema (OWASP API10) y errores traducidos al formato estándar de la API.
 */
final class IaService
{
    public const LIMITE_MINUTO = 10;

    private const INSTRUCCIONES = <<<'TXT'
        Eres analista de gestión de un centro terapéutico infantil y adulto en Chile (terapia ocupacional,
        fonoaudiología, psicología) que además arrienda boxes por hora a profesionales externos.
        Recibirás indicadores agregados de un período y del período anterior. Responde SOLO un objeto JSON con:
        {"resumen": string (máx. 90 palabras, español de Chile, tono profesional),
         "hallazgos": arreglo de 2 a 4 strings (cada uno con una cifra concreta del período),
         "recomendaciones": arreglo de 2 a 4 strings (acciones concretas y realizables por la administración)}.
        No inventes datos que no estén en la entrada. No menciones personas. Si un indicador es null, dilo.
        TXT;

    private const ENFOQUES = [
        'general' => 'Analiza el desempeño general del centro.',
        'ocupacion' => 'Pon el foco en la ocupación de los boxes y la asistencia de los pacientes.',
        'cobranza' => 'Pon el foco en los ingresos, las cuentas por cobrar y los días de pago.',
    ];

    public function __construct(
        private readonly IaClient $ia,
        private readonly DashboardService $dashboard,
        private readonly RateLimiter $limiter,
        private readonly AuditoriaRepository $auditoria,
    ) {
    }

    public function resumen(AuthUser $u, array $body): array
    {
        $d = Validator::check($body, [
            'desde' => ['required', 'date'],
            'hasta' => ['required', 'date'],
            'enfoque' => ['nullable', 'in:' . implode(',', array_keys(self::ENFOQUES))],
            'especialidad' => ['nullable', 'string', 'max:60', 'regex:/^[a-z0-9-]+$/'],
        ]);
        if ($d['desde'] > $d['hasta']) {
            throw ApiException::field('hasta', 'La fecha final debe ser igual o posterior a la inicial.');
        }
        if ((strtotime($d['hasta']) - strtotime($d['desde'])) / 86400 > 366) {
            throw ApiException::field('desde', 'El resumen con IA admite como máximo un año.');
        }
        if (!$this->ia->configurado()) {
            throw new ApiException(503, 'IA_NO_CONFIGURADA', 'El servicio de IA no está configurado en este servidor (falta la variable GROQ_API_KEY).');
        }
        $this->limiter->hit('ia:' . $u->id, self::LIMITE_MINUTO, 60);

        $f = $this->dashboard->filtros(array_filter(['desde' => $d['desde'], 'hasta' => $d['hasta'], 'especialidad' => $d['especialidad'] ?? null]));
        $indicadores = $this->dashboard->indicadores($f);
        $entrada = [
            'periodo' => ['desde' => $f['desde'], 'hasta' => $f['hasta'], 'anterior' => DashboardService::anterior($f)],
            'enfoque' => self::ENFOQUES[$d['enfoque'] ?? 'general'],
            'indicadores' => array_map(fn ($i) => ['nombre' => $i['nombre'], 'unidad' => $i['unidad'], 'valor' => $i['valor'],
                'periodo_anterior' => $i['anterior'], 'mejor_si' => $i['mejor'] === 'alto' ? 'sube' : 'baja'], $indicadores),
        ];

        $t0 = hrtime(true);
        try {
            $r = $this->ia->completarJson(self::INSTRUCCIONES, json_encode($entrada, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $salida = $this->validarRespuesta($r);
        } catch (IaException $e) {
            $this->auditoria->registrarIa($u->id, 'resumen', false, (int) ((hrtime(true) - $t0) / 1e6), $e->tipo . ': ' . $e->getMessage());
            throw match ($e->tipo) {
                IaException::TIMEOUT => new ApiException(504, 'IA_TIEMPO_AGOTADO', 'El servicio de IA no respondió a tiempo. Intenta de nuevo en unos minutos.'),
                IaException::SATURADO => new ApiException(503, 'IA_SATURADA', 'El servicio de IA está saturado. Intenta de nuevo en unos minutos.', [], ['Retry-After' => '60']),
                default => new ApiException(502, 'IA_ERROR', 'El servicio de IA devolvió un error. El resto del sistema sigue funcionando.'),
            };
        }
        $ms = (int) ((hrtime(true) - $t0) / 1e6);
        $this->auditoria->registrarIa($u->id, 'resumen', true, $ms, null);
        return $salida + ['modelo' => $this->ia->modelo(), 'generado_en' => date('c'), 'latencia_ms' => $ms, 'indicadores' => $indicadores,
            'aviso' => 'Texto generado por IA a partir de indicadores agregados. Revísalo antes de tomar decisiones.'];
    }

    /** La respuesta del modelo es dato externo: se valida su forma y se recorta (OWASP API10). */
    private function validarRespuesta(array $r): array
    {
        $texto = fn ($v, int $max) => mb_substr(trim(strip_tags((string) $v)), 0, $max);
        $lista = function ($v) use ($texto): array {
            if (!is_array($v)) {
                return [];
            }
            return array_values(array_filter(array_map(fn ($x) => is_scalar($x) ? $texto($x, 400) : '', array_slice($v, 0, 5)), fn ($x) => $x !== ''));
        };
        $out = ['resumen' => $texto($r['resumen'] ?? '', 1200), 'hallazgos' => $lista($r['hallazgos'] ?? null), 'recomendaciones' => $lista($r['recomendaciones'] ?? null)];
        if ($out['resumen'] === '' || $out['recomendaciones'] === []) {
            throw new IaException(IaException::RESPUESTA, 'La respuesta del modelo no trae resumen o recomendaciones');
        }
        return $out;
    }
}

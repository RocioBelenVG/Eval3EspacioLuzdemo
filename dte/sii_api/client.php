<?php
declare(strict_types=1);

final class TrivoxaApiClient
{
    private array $cfg;
    /** XML temporales (DTE/envío firmados) creados en esta instancia, para limpiarlos. */
    private array $tempFiles = [];

    public function __construct(array $cfg)
    {
        // EspacioLuz Demo: este cliente NUNCA se usa; cualquier intento de crearlo se corta.
        if (!function_exists('dte_modo_demo') || dte_modo_demo()) {
            throw new RuntimeException('Modo demostración: la conexión con Trivoxa API DTE / SII está deshabilitada.');
        }
        $this->cfg = $cfg;

        if (empty($this->cfg['base_url'])) {
            throw new RuntimeException("Falta base_url en dte/config.php (Trivoxa API DTE)");
        }
    }

    public function __destruct()
    {
        // Los XML temporales contienen el DTE timbrado (material sensible): se borran
        // al destruirse el cliente en vez de acumularse en el directorio temporal.
        foreach ($this->tempFiles as $f) { if (is_string($f) && $f !== '' && is_file($f)) @unlink($f); }
        $this->tempFiles = [];
    }

    private function baseUrl(): string
    {
        return rtrim((string)$this->cfg['base_url'], '/');
    }

    private function timeout(): int
    {
        return (int)($this->cfg['timeout'] ?? 90);
    }

    private function connectTimeout(): int
    {
        return (int)($this->cfg['connect_timeout'] ?? 20);
    }

    private function authHeaders(bool $json = false): array
    {
        $headers = [];

        if ($json) {
            $headers[] = "Accept: application/json";
            $headers[] = "Content-Type: application/json";
        } else {
            $headers[] = "Accept: application/json";
        }

        $user = (string)($this->cfg['api_user'] ?? '');
        $pass = (string)($this->cfg['api_pass'] ?? '');

        if ($user !== '' || $pass !== '') {
            $basic = base64_encode($user . ":" . $pass);
            $headers[] = "Authorization: Basic {$basic}";
        }

        $apiKey = (string)($this->cfg['api_key'] ?? '');
        if ($apiKey !== '') {
            $headers[] = "ApiKey: {$apiKey}";
            $headers[] = "X-Api-Key: {$apiKey}";
        }

        return $headers;
    }

    private function decodeBodyIfJson(string $body)
    {
        $trim = trim($body);
        if ($trim === '') {
            return '';
        }

        if (str_starts_with($trim, '{') || str_starts_with($trim, '[')) {
            $dec = json_decode($trim, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $dec;
            }
        }

        return $body;
    }

    private function postJson(string $url, array $payload): array
    {
        $ch = curl_init();

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => $this->authHeaders(true),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout(),
            CURLOPT_TIMEOUT => $this->timeout(),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $resp = curl_exec($ch);

        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);

            return [
                'ok' => false,
                'http' => 0,
                'response_headers' => '',
                'body' => '',
                'error' => "cURL: {$err}",
            ];
        }

        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hsz  = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        $rawHeaders = (string)substr($resp, 0, $hsz);
        $bodyRaw    = (string)substr($resp, $hsz);

        curl_close($ch);

        $ok = ($http >= 200 && $http < 300);
        $this->logCall($url, $http, $bodyRaw);

        return [
            'ok' => $ok,
            'http' => $http,
            'response_headers' => $rawHeaders,
            'body' => $this->decodeBodyIfJson($bodyRaw),
            'error' => $ok ? null : trim($bodyRaw ?: "HTTP {$http}"),
        ];
    }

    private function postMultipart(string $url, array $fields, array $files): array
    {
        $ch = curl_init();
        $post = [];

        foreach ($fields as $k => $v) {
            $post[$k] = $v;
        }

        foreach ($files as $fieldName => $fileObj) {
            if ($fileObj instanceof CURLFile) {
                $post[$fieldName] = $fileObj;
                continue;
            }

            $path = (string)$fileObj;
            if (!$path || !is_file($path)) {
                return [
                    'ok' => false,
                    'http' => 0,
                    'response_headers' => '',
                    'body' => '',
                    'error' => "Archivo no encontrado: {$fieldName} => {$path}",
                ];
            }

            $mime = 'application/octet-stream';
            $lower = strtolower($path);

            if (str_ends_with($lower, '.xml')) {
                $mime = 'application/xml';
            } elseif (str_ends_with($lower, '.pfx') || str_ends_with($lower, '.p12')) {
                $mime = 'application/x-pkcs12';
            }

            $post[$fieldName] = new CURLFile($path, $mime, basename($path));
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post,
            CURLOPT_HTTPHEADER => $this->authHeaders(false),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout(),
            CURLOPT_TIMEOUT => $this->timeout(),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $resp = curl_exec($ch);

        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);

            return [
                'ok' => false,
                'http' => 0,
                'response_headers' => '',
                'body' => '',
                'error' => "cURL: {$err}",
            ];
        }

        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hsz  = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        $rawHeaders = (string)substr($resp, 0, $hsz);
        $bodyRaw    = (string)substr($resp, $hsz);

        curl_close($ch);

        $ok = ($http >= 200 && $http < 300);
        $this->logCall($url, $http, $bodyRaw);

        return [
            'ok' => $ok,
            'http' => $http,
            'response_headers' => $rawHeaders,
            'body' => $this->decodeBodyIfJson($bodyRaw),
            'error' => $ok ? null : trim($bodyRaw ?: "HTTP {$http}"),
        ];
    }

    private function detectarTipoDTE(array $input): int
    {
        return (int)(
            $input['Documento']['Encabezado']['IdentificacionDTE']['TipoDTE']
            ?? $input['Documento']['Encabezado']['IdDoc']['TipoDTE']
            ?? 0
        );
    }

    private function obtenerRutaCAF(int $tipoDte): string
    {
        // Resolución genérica: soporta TODOS los tipos de DTE.
        // 1) Clave directa cafNN_path (la inyecta lib/dte_cred.php por empresa).
        $key = "caf{$tipoDte}_path";
        if (!empty($this->cfg[$key])) {
            return (string)$this->cfg[$key];
        }
        // 2) Compatibilidad con configs antiguos que usaban nombres fijos.
        return match ($tipoDte) {
            33  => (string)($this->cfg['caf33_path']  ?? ''),
            34  => (string)($this->cfg['caf34_path']  ?? ''),
            43  => (string)($this->cfg['caf43_path']  ?? ''),
            46  => (string)($this->cfg['caf46_path']  ?? ''),
            52  => (string)($this->cfg['caf52_path']  ?? ''),
            56  => (string)($this->cfg['caf56_path']  ?? ''),
            61  => (string)($this->cfg['caf61_path']  ?? ''),
            110 => (string)($this->cfg['caf110_path'] ?? ''),
            111 => (string)($this->cfg['caf111_path'] ?? ''),
            112 => (string)($this->cfg['caf112_path'] ?? ''),
            default => '',
        };
    }

    private function guardarXmlTemporal(string $prefix, string $xml, string $extension = 'xml'): string
    {
        $dir = (string)($this->cfg['tmp_dir'] ?? sys_get_temp_dir());

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        if (!is_dir($dir)) {
            throw new RuntimeException("No existe tmp_dir: {$dir}");
        }

        $file = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . $prefix . '_' . date('Ymd_His') . '_' . mt_rand(1000, 9999) . '.' . $extension;

        $ok = file_put_contents($file, $xml);
        if ($ok === false) {
            throw new RuntimeException("No se pudo crear archivo temporal: {$file}");
        }

        if (!is_file($file)) {
            throw new RuntimeException("Archivo temporal no creado: {$file}");
        }

        $this->tempFiles[] = $file; // se borra en __destruct
        return $file;
    }

    private function leerContenidoBase64ComoXml(array $input): string
    {
        $contenido = (string)($input['contenido'] ?? '');
        if ($contenido === '') {
            throw new RuntimeException("Falta contenido en input");
        }

        $xml = base64_decode($contenido, true);
        if ($xml === false || trim($xml) === '') {
            throw new RuntimeException("El contenido base64 es inválido o vacío");
        }

        return $xml;
    }

    public function generarDTE(array $input): array
    {
        $endpoint = $this->baseUrl() . "/api/v1/DTE/generar";

        $tipoDte  = $this->detectarTipoDTE($input);
        $certPath = (string)($this->cfg['cert_path'] ?? '');
        $cafPath  = $this->obtenerRutaCAF($tipoDte);

        if (!$certPath || !is_file($certPath)) {
            return ['ok' => false, 'http' => 0, 'error' => "No existe PFX: {$certPath}"];
        }

        if (!$cafPath || !is_file($cafPath)) {
            return ['ok' => false, 'http' => 0, 'error' => "No existe CAF para TipoDTE {$tipoDte}: {$cafPath}"];
        }

        $fields = [
            'input' => json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        $files = [
            'files'  => $certPath,
            'files2' => $cafPath,
        ];

        return $this->postMultipart($endpoint, $fields, $files);
    }

    public function generarEnvio(array $input): array
    {
        $endpoint = $this->baseUrl() . "/api/v1/Envio/generar";
        $certPath = (string)($this->cfg['cert_path'] ?? '');

        if (!$certPath || !is_file($certPath)) {
            return ['ok' => false, 'http' => 0, 'error' => "No existe PFX: {$certPath}"];
        }

        try {
            $xmlDte = $this->leerContenidoBase64ComoXml($input);
            $xmlDtePath = $this->guardarXmlTemporal('dte_generado', $xmlDte);

            $payload = $input;
            $payload['tipo'] = 0;

            if (isset($payload['caratula']) && is_array($payload['caratula'])) {
                $payload['caratula']['rutReceptor'] = '60803000-K';
            }

            $fields = [
                'input' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];

            $files = [
                'files'  => $certPath,
                'files2' => $xmlDtePath,
            ];

            return $this->postMultipart($endpoint, $fields, $files);
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'http' => 0,
                'response_headers' => '',
                'body' => '',
                'error' => $e->getMessage(),
            ];
        }
    }

    public function enviarEnvio(array $input): array
    {
        $endpoint = $this->baseUrl() . "/api/v1/Envio/enviar";
        $certPath = (string)($this->cfg['cert_path'] ?? '');

        if (!$certPath || !is_file($certPath)) {
            return ['ok' => false, 'http' => 0, 'error' => "No existe PFX: {$certPath}"];
        }

        try {
            $xmlEnvio = $this->leerContenidoBase64ComoXml($input);
            $xmlEnvioPath = $this->guardarXmlTemporal('envio_dte', $xmlEnvio);

            $payload = $input;
            // EnvioType al SII: 1 = EnvioDTE (facturas y demás), 2 = EnvioBOLETA
            // (boletas 39/41). El sobre lo genera generarEnvio auto-detectando el
            // tipo; aquí hay que enviarlo con el EnvioType que corresponde al XML,
            // o el SII responde "El tipo EnvioDTE no corresponde al XML recibido".
            $payload['tipo'] = isset($input['tipo']) ? (int)$input['tipo'] : 1;

            $fields = [
                'input' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];

            $files = [
                'files'  => $certPath,
                'files2' => $xmlEnvioPath,
            ];

            return $this->postMultipart($endpoint, $fields, $files);
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'http' => 0,
                'response_headers' => '',
                'body' => '',
                'error' => $e->getMessage(),
            ];
        }
    }

    public function consultarEnvio(array $input): array
    {
        $endpoint = $this->baseUrl() . "/api/v1/Consulta/envio";
        $certPath = (string)($this->cfg['cert_path'] ?? '');

        if (!$certPath || !is_file($certPath)) {
            return ['ok' => false, 'http' => 0, 'error' => "No existe PFX: {$certPath}"];
        }

        $fields = [
            'input' => json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        $files = [
            'files' => $certPath,
        ];

        return $this->postMultipart($endpoint, $fields, $files);
    }
    private function logCall(string $url, int $http, string $body): void
    {
        $dir = (string)($this->cfg['debug_log_dir'] ?? '');
        if ($dir === '' || !is_dir($dir) || !is_writable($dir)) return;
        $line = '[' . date('Y-m-d H:i:s') . "] {$url} HTTP {$http}\n" . substr($body, 0, 1500) . "\n----\n";
        @file_put_contents($dir . '/trivoxaapi-' . date('Ymd') . '.log', $line, FILE_APPEND);
    }
}

<?php
/** Documentación interactiva de la API (Swagger UI) a partir del contrato docs/openapi.yaml. */
declare(strict_types=1);
$contrato = base_path('/api/v1/openapi.yaml');
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>API REST v1 — Documentación · Espacio Luz Demo</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/swagger-ui/5.17.14/swagger-ui.min.css">
    <style>body { margin: 0; background: #FBF9F4; } .topbar { display: none; } header { padding: 1rem 1.5rem; background: #3D2A21; color: #FBF9F4; font-family: system-ui, sans-serif; }
        header a { color: #FBF9F4; }</style>
</head>
<body>
<header><strong>Espacio Luz Demo · API REST v1</strong> — contrato OpenAPI 3.1: <a href="<?= e($contrato) ?>">openapi.yaml</a>.
    Para probar, obtén un token con <code>POST /auth/login</code> y usa «Authorize».</header>
<main id="swagger" aria-label="Documentación de la API"></main>
<script src="https://cdnjs.cloudflare.com/ajax/libs/swagger-ui/5.17.14/swagger-ui-bundle.min.js"></script>
<script>
    SwaggerUIBundle({ url: <?= json_encode($contrato) ?>, dom_id: '#swagger', deepLinking: true, persistAuthorization: false, tryItOutEnabled: false });
</script>
</body>
</html>

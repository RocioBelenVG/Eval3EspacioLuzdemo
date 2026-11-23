<?php
/**
 * Configuración de Phinx (migraciones versionadas y datos sintéticos).
 * Las credenciales salen del .env (nunca del repositorio), igual que la aplicación.
 *
 *   vendor/bin/phinx migrate -c phinx.php                     aplica las migraciones pendientes
 *   vendor/bin/phinx rollback -c phinx.php                    revierte la última
 *   vendor/bin/phinx seed:run -c phinx.php -s DatosSinteticos carga 12 meses de datos de prueba
 */
require_once __DIR__ . '/lib/env.php';

return [
    'paths' => [
        'migrations' => '%%PHINX_CONFIG_DIR%%/database/migrations',
        'seeds'      => '%%PHINX_CONFIG_DIR%%/database/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment'     => 'app',
        'app' => [
            'adapter'   => 'mysql',
            'host'      => env('DB_HOST', 'localhost'),
            'port'      => (int) env('DB_PORT', '3306'),
            'name'      => env('DB_NAME', ''),
            'user'      => env('DB_USER', ''),
            'pass'      => env('DB_PASS', ''),
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ],
    ],
    'version_order' => 'creation',
];

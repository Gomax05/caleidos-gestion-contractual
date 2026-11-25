<?php
declare(strict_types=1);

return [
    'driver' => getenv('DB_DRIVER') ?: 'sqlite',
    'sqlite_path' => getenv('DB_SQLITE_PATH') ?: dirname(__DIR__) . '/storage/caleidos.sqlite',
    'sqlsrv_dsn' => getenv('DB_SQLSRV_DSN') ?: '',
    'username' => getenv('DB_USERNAME') ?: '',
    'password' => getenv('DB_PASSWORD') ?: '',
];

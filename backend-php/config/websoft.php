<?php

/*
|--------------------------------------------------------------------------
| Websoft Service ERP Solution -- application settings
|--------------------------------------------------------------------------
|
| Mirrors backend/app/core/config.py's Settings class. Values are read
| from environment variables (or a local .env file, not committed to
| git). Defaults here are for local development only.
*/

return [
    'app_name' => env('APP_NAME', 'Websoft Service ERP Solution'),
    'environment' => env('APP_ENV', 'development'),

    // Demo-only secret. Must be overridden via env var before any real
    // deployment.
    'jwt_secret_key' => env('JWT_SECRET', 'dev-only-secret-do-not-use-in-production'),
    'jwt_algorithm' => env('JWT_ALGO', 'HS256'),
    'access_token_expire_minutes' => (int) env('JWT_ACCESS_TOKEN_EXPIRE_MINUTES', 60 * 8),

    // Outbound email -- one shared mailbox/relay for the whole install,
    // not per-company. Unset by default so "Email" fails with a clear
    // message instead of silently pretending to send.
    'smtp_host' => env('SMTP_HOST'),
    'smtp_port' => (int) env('SMTP_PORT', 587),
    'smtp_username' => env('SMTP_USERNAME'),
    'smtp_password' => env('SMTP_PASSWORD'),
    'smtp_use_tls' => (bool) env('SMTP_USE_TLS', true),
    'smtp_from_email' => env('SMTP_FROM_EMAIL'),
    'smtp_from_name' => env('SMTP_FROM_NAME', 'Web Master Consultancy'),

    // File uploads -- stored on local disk; in Docker this is a named
    // volume so files persist across container restarts.
    'uploads_dir' => env('UPLOADS_DIR', storage_path('app/uploads')),
];

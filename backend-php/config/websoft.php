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

    // WhatsApp OTP (planned-work.md "WhatsApp OTP as a second login
    // factor") -- a second, optional login-code channel alongside
    // email. Blocked until a real WhatsApp Business API account is
    // provisioned; unset by default so WhatsAppSender::isConfigured()
    // is false and the login flow behaves exactly as it does today
    // (email only). Twilio's WhatsApp API is the first provider this
    // is built against -- see WhatsAppSender's docblock for why, and
    // for how to add a second provider without touching AuthController.
    'twilio_account_sid' => env('TWILIO_ACCOUNT_SID'),
    'twilio_auth_token' => env('TWILIO_AUTH_TOKEN'),
    // E.164, no "whatsapp:" prefix -- WhatsAppSender adds that itself.
    'twilio_whatsapp_from' => env('TWILIO_WHATSAPP_FROM'),

    // File uploads -- stored on local disk; in Docker this is a named
    // volume so files persist across container restarts.
    'uploads_dir' => env('UPLOADS_DIR', storage_path('app/uploads')),

    // Shared secret for the host-side upgrade agent (deploy/upgrade-agent.sh).
    // Generated into .env by deploy/install.sh / upgrade.sh. Empty = agent
    // endpoints disabled (503). See App\Http\Controllers\Api\UpgradeAgentController.
    'upgrade_agent_token' => env('UPGRADE_AGENT_TOKEN'),

    // Data Migration (docs/data-migration.md): a dry run or import runs
    // as a background `php artisan data-migration:run` process, so a
    // large file can outlast a web request. MIGRATION_BACKGROUND=false
    // runs it inside the request instead. PHP_CLI is the command-line
    // PHP binary (php-fpm's own binary cannot run artisan).
    'migration_background' => env('MIGRATION_BACKGROUND', true),
    'php_cli' => env('PHP_CLI', 'php'),
];

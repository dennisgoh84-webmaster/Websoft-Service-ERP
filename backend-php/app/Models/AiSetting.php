<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The AI Assistant's install-level settings -- one row, key 'default'.
 * The API key is write-only (hidden from every read, encrypted at
 * rest); `.env`'s ANTHROPIC_API_KEY is the bootstrap fallback, the same
 * arrangement as the OTP mailbox. See App\Services\Ai\AiClient.
 */
class AiSetting extends Model
{
    public const KEY = 'default';

    public const DEFAULT_MODEL = 'claude-opus-5';

    protected $table = 'ai_settings';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['key', 'api_key', 'model', 'redact_personal_data', 'updated_at'];

    protected $hidden = ['api_key'];

    protected $casts = [
        'api_key' => 'encrypted',
        'redact_personal_data' => 'boolean',
        'updated_at' => 'datetime',
    ];

    public static function current(): self
    {
        return self::firstOrNew(['key' => self::KEY], ['model' => self::DEFAULT_MODEL, 'redact_personal_data' => true]);
    }

    /** The key in use: the stored one, else the .env bootstrap value. */
    public function effectiveApiKey(): ?string
    {
        $stored = $this->api_key;
        if (is_string($stored) && $stored !== '') {
            return $stored;
        }
        $env = (string) env('ANTHROPIC_API_KEY', '');

        return $env !== '' ? $env : null;
    }

    public function getApiKeySetAttribute(): bool
    {
        return $this->effectiveApiKey() !== null;
    }

    /** True when the key comes from .env rather than this row. */
    public function getApiKeyFromEnvAttribute(): bool
    {
        return ! (is_string($this->api_key) && $this->api_key !== '') && $this->effectiveApiKey() !== null;
    }
}

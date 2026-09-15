<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/**
 * One call to the model provider -- the audit of AI interactions
 * (decision 12.3). Stores what came back and what it cost in tokens,
 * never the prompt (which can carry customer text).
 */
class AiInteraction extends Model
{
    use HasUuidPrimaryKey;

    public const FEATURE_INCIDENT_TRIAGE = 'incident_triage';

    public const FEATURE_CONNECTION_TEST = 'connection_test';

    public const STATUS_OK = 'ok';

    public const STATUS_REFUSED = 'refused';

    public const STATUS_ERROR = 'error';

    public $timestamps = false;

    protected $fillable = [
        'company_id', 'user_id', 'feature', 'entity_type', 'entity_id', 'model', 'status', 'error',
        'input_tokens', 'output_tokens', 'response', 'created_at',
    ];

    protected $casts = [
        'response' => 'array',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'created_at' => 'datetime',
    ];
}

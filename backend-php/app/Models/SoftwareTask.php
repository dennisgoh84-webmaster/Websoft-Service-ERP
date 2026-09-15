<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A task a programmer enters: what it is, who is writing it, which
 * modules it affects, a target finish date, manually keyed hours, and
 * who is testing it. Mirrors backend/app/models/software_tasks.py.
 *
 * Confirmed 2026-09-10 as a minimal first slice -- no status workflow
 * beyond `is_tested`, deliberately, pending real usage.
 *
 * Read by Support Monitoring as "Un-Test S/T" (assigned for testing but
 * not yet tested), which reported a placeholder 0 until this module
 * existed.
 */
class SoftwareTask extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    public $timestamps = false;

    protected $fillable = [
        'company_id', 'title', 'description', 'modules_affected',
        'assigned_programmer_id', 'programming_finish_date', 'programming_hours',
        'tester_user_id', 'is_tested', 'tested_at', 'created_by_user_id',
    ];

    protected $attributes = ['is_tested' => false];

    protected $casts = [
        'programming_finish_date' => 'date',
        'programming_hours' => 'decimal:2',
        'is_tested' => 'boolean',
        'tested_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function assignedProgrammer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_programmer_id');
    }

    public function tester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tester_user_id');
    }
}

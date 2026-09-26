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
 * Confirmed 2026-09-10 as a minimal first slice (tested / not tested
 * only). Statuses added 2026-09-26 (decision 12.1): Open -> Programming
 * -> For Testing -> Tested -> Released, moved by
 * SoftwareTaskController::changeStatus along TRANSITIONS. `is_tested`
 * stays in step (true for Tested and Released).
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
        'status', 'status_changed_at', 'released_at', 'released_by_user_id',
    ];

    public const STATUS_OPEN = 'open';

    public const STATUS_PROGRAMMING = 'programming';

    public const STATUS_FOR_TESTING = 'for_testing';

    public const STATUS_TESTED = 'tested';

    public const STATUS_RELEASED = 'released';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_PROGRAMMING, self::STATUS_FOR_TESTING, self::STATUS_TESTED, self::STATUS_RELEASED];

    /**
     * Where a task may move from each status. Forward one step at a
     * time (Open may skip straight to For Testing); a failed test goes
     * back to Programming; a Tested task can be reopened for testing.
     * Released -- the change has gone out -- only from Tested, and is
     * final (decision 12.1: "Anyone with EDIT on Software Development").
     */
    public const TRANSITIONS = [
        self::STATUS_OPEN => [self::STATUS_PROGRAMMING, self::STATUS_FOR_TESTING],
        self::STATUS_PROGRAMMING => [self::STATUS_FOR_TESTING, self::STATUS_OPEN],
        self::STATUS_FOR_TESTING => [self::STATUS_TESTED, self::STATUS_PROGRAMMING],
        self::STATUS_TESTED => [self::STATUS_RELEASED, self::STATUS_FOR_TESTING],
        self::STATUS_RELEASED => [],
    ];

    protected $attributes = ['is_tested' => false, 'status' => self::STATUS_OPEN];

    protected $casts = [
        'programming_finish_date' => 'date',
        'programming_hours' => 'decimal:2',
        'is_tested' => 'boolean',
        'tested_at' => 'datetime',
        'created_at' => 'datetime',
        'status_changed_at' => 'datetime',
        'released_at' => 'datetime',
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

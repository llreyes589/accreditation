<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccreditationDecision extends Model
{
    // Ledger is append-only: no updated_at column.
    public const UPDATED_AT = null;

    public const OUTCOME_DRAFT = 'draft';
    public const OUTCOME_APPROVED = 'approved';
    public const OUTCOME_PROBATIONARY = 'probationary';
    public const OUTCOME_REJECTED = 'rejected';

    /** Committee recommendations captured during deliberation. */
    public const RECOMMENDATION_3_YEARS = '3_years';
    public const RECOMMENDATION_3_YEARS_CONDITIONAL = '3_years_conditional';
    public const RECOMMENDATION_1_YEAR = '1_year';

    protected $fillable = [
        'accreditation_id',
        'outcome',
        'recommendation',
        'vote_count',
        'notes',
        'valid_from',
        'valid_until',
        'decided_by',
        'decided_at',
    ];

    protected $casts = [
        'valid_from' => 'date',
        'valid_until' => 'date',
        'decided_at' => 'datetime',
    ];

    public function accreditation(): BelongsTo
    {
        return $this->belongsTo(Accreditation::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}

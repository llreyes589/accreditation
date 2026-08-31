<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The captured CART inspection for one accreditation.
 * `answers` is a JSON map keyed by checklist_item id:
 *   { "<itemId>": { "compliant": bool, "notes": string } }
 */
class AccreditationInspection extends Model
{
    use SoftDeletes;

    /**
     * Maximum inspections a single accreditor may be assigned to on the same
     * calendar day (lead or member), per the operating rule.
     */
    public const MAX_PER_ACCREDITOR_PER_DAY = 3;

    /**
     * Maximum total accreditors (lead + members) assignable to a single
     * inspection, per the operating rule ("tatlo kayong creditor").
     */
    public const MAX_ACCREDITORS_PER_INSPECTION = 3;

    protected $fillable = [
        'accreditation_id', 'accreditor_id', 'inspection_scheduled_at',
        'conducted_at', 'status', 'answers',
    ];
    protected $casts = [
        'inspection_scheduled_at' => 'date',
        'conducted_at' => 'datetime',
        'answers' => 'array',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_SUBMITTED = 'submitted';

    public function accreditation()
    {
        return $this->belongsTo(Accreditation::class);
    }
    public function accreditor()
    {
        return $this->belongsTo(User::class, 'accreditor_id');
    }

    /** All accreditors assigned to this inspection (lead + members). */
    public function accreditorAssignments()
    {
        return $this->hasMany(InspectionAccreditor::class);
    }

    public function accreditors()
    {
        return $this->belongsToMany(User::class, 'accreditation_inspection_accreditors')
            ->withPivot(['role', 'status', 'assigned_at', 'responded_at', 'decline_reason'])
            ->withTimestamps()
            ->wherePivot('status', '!=', InspectionAccreditor::STATUS_REMOVED);
    }

    /** The assigned lead accreditor (denormalized for quick lookups). */
    public function leadAccreditor()
    {
        return $this->belongsTo(User::class, 'accreditor_id');
    }

    /** Findings raised against this inspection. */
    public function findings()
    {
        return $this->hasMany(Finding::class, 'accreditation_inspection_id');
    }
}

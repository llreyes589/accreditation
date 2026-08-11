<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;
use OwenIt\Auditing\Auditable as AuditableTrait;

class Accreditation extends Model implements Auditable
{
    use SoftDeletes, AuditableTrait;
    public const STATUS_PENDING = 'pending';
    public const STATUS_REQUIREMENTS_COMPLETED = 'requirements_completed';
    public const STATUS_INSPECTION_SCHEDULED = 'inspection_scheduled';
    public const STATUS_INSPECTED = 'inspected';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    protected $fillable = ['institution_id', 'checklist_snapshot', 'approved_by', 'valid_from', 'valid_until', 'status', 'submission_type', 'inspection_scheduled_at', 'submitted_at'];
    protected $casts = ['checklist_snapshot' => 'array', 'valid_from' => 'date', 'valid_until' => 'date', 'inspection_scheduled_at' => 'date', 'submitted_at' => 'date'];
    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }
    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
    public function inspections()
    {
        return $this->hasMany(AccreditationInspection::class);
    }
    public function latestInspection()
    {
        return $this->hasOne(AccreditationInspection::class)->latestOfMany();
    }

    /** Document types required to submit an accreditation application. */
    public const REQUIRED_DOC_TYPES = [
        'lto_clinical_lab',
        'lto_bsf',
        'chairman_designation',
        'psp_certificate',
        'floor_plan',
        'org_chart',
        'rotation_schedule',
        'conference_schedule',
        'activity_schedule',
    ];

    /** Returns the required doc types the institution has NOT yet uploaded. */
    public function missingDocuments(): array
    {
        $have = $this->institution->documents()->whereIn('type', self::REQUIRED_DOC_TYPES)->pluck('type')->all();
        return array_values(array_diff(self::REQUIRED_DOC_TYPES, $have));
    }
}

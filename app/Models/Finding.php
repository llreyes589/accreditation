<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Finding extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'accreditation_inspection_id',
        'checklist_item_id',
        'title',
        'description',
        'severity',
        'status',
        'raised_by',
    ];

    public const STATUS_OPEN = 'open';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_REJECTED = 'rejected';

    public function inspection()
    {
        return $this->belongsTo(AccreditationInspection::class, 'accreditation_inspection_id');
    }

    public function checklistItem()
    {
        return $this->belongsTo(ChecklistItem::class);
    }

    public function actions()
    {
        return $this->hasMany(CorrectiveAction::class);
    }

    public function raisedBy()
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    /** The institution that owns this finding (via inspection → accreditation). */
    public function institution()
    {
        return $this->inspection->accreditation->institution();
    }
}

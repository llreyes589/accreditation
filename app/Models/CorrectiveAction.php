<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CorrectiveAction extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'finding_id', 'action_plan', 'due_date', 'status', 'assigned_to', 'created_by',
    ];
    protected $casts = ['due_date' => 'date'];

    public const STATUS_OPEN = 'open';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_REOPENED = 'reopened';

    public function finding()
    {
        return $this->belongsTo(Finding::class);
    }

    public function evidence()
    {
        return $this->hasMany(CorrectiveActionEvidence::class);
    }

    public function statusLogs()
    {
        return $this->hasMany(CorrectiveActionStatusLog::class)->orderBy('logged_at');
    }

    public function assignedTo()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

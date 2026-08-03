<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;
use OwenIt\Auditing\Auditable as AuditableTrait;

class Accreditation extends Model implements Auditable
{
    use SoftDeletes, AuditableTrait;
    protected $fillable = ['institution_id', 'checklist_snapshot', 'approved_by', 'valid_from', 'valid_until', 'status'];
    protected $casts = ['checklist_snapshot' => 'array', 'valid_from' => 'date', 'valid_until' => 'date'];
    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }
    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}

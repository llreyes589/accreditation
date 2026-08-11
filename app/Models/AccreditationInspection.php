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
}

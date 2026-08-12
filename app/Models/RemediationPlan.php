<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RemediationPlan extends Model
{
    protected $fillable = ['resident_id', 'reason', 'plan', 'status', 'target_date'];
    protected $casts = ['target_date' => 'date'];

    public function resident()
    {
        return $this->belongsTo(Resident::class);
    }
}

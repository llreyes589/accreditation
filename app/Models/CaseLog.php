<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CaseLog extends Model
{
    use SoftDeletes;
    protected $fillable = ['resident_id', 'case_type', 'procedure', 'count', 'logged_at'];
    protected $casts = ['logged_at' => 'datetime'];
    public function resident()
    {
        return $this->belongsTo(Resident::class);
    }
}

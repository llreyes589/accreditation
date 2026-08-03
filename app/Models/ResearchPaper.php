<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ResearchPaper extends Model
{
    use SoftDeletes;
    protected $fillable = ['resident_id', 'title', 'stage', 'notes'];
    public function resident()
    {
        return $this->belongsTo(Resident::class);
    }
}

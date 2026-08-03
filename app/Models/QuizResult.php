<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class QuizResult extends Model
{
    use SoftDeletes;
    protected $fillable = ['quiz_id', 'resident_id', 'score', 'taken_at'];
    protected $casts = ['taken_at' => 'datetime'];
    public function quiz()
    {
        return $this->belongsTo(Quiz::class);
    }
    public function resident()
    {
        return $this->belongsTo(Resident::class);
    }
}

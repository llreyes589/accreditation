<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Quiz extends Model
{
    use SoftDeletes;
    protected $fillable = ['institution_id', 'title', 'type', 'max_score', 'created_by'];
    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }
    public function results()
    {
        return $this->hasMany(QuizResult::class);
    }
}

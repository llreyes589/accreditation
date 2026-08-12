<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConsultantEvaluation extends Model
{
    protected $fillable = [
        'resident_id', 'consultant_id', 'period', 'ratings', 'comments', 'recommendation', 'evaluated_at',
    ];
    protected $casts = ['ratings' => 'array', 'evaluated_at' => 'date'];

    public function resident()
    {
        return $this->belongsTo(Resident::class);
    }

    public function consultant()
    {
        return $this->belongsTo(Consultant::class);
    }
}

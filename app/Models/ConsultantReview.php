<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConsultantReview extends Model
{
    protected $fillable = ['rotation_assignment_id', 'consultant_id', 'status', 'comments'];

    public function assignment()
    {
        return $this->belongsTo(RotationAssignment::class, 'rotation_assignment_id');
    }

    public function consultant()
    {
        return $this->belongsTo(Consultant::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class InstitutionDocument extends Model
{
    use SoftDeletes;
    protected $fillable = ['institution_id', 'type', 'file_path', 'expires_at'];
    protected $casts = ['expires_at' => 'date'];
    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }
}

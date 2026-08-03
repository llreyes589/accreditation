<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Consultant extends Model
{
    use SoftDeletes;
    protected $fillable = ['institution_id', 'name', 'specialty', 'credentials', 'linked_documents'];
    protected $casts = ['linked_documents' => 'array'];
    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }
}

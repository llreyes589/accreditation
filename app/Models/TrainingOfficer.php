<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TrainingOfficer extends Model
{
    use SoftDeletes;
    protected $fillable = ['user_id', 'institution_id', 'phone', 'telegram_handle'];
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }
}

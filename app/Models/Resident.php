<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Resident extends Model
{
    use SoftDeletes;
    protected $fillable = ['user_id', 'institution_id', 'track', 'date_accepted', 'age_at_enrollment', 'year_level', 'promotion_status', 'promotion_evaluated_at'];
    protected $casts = ['date_accepted' => 'date', 'promotion_evaluated_at' => 'datetime'];
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }
    public function results()
    {
        return $this->hasMany(QuizResult::class);
    }
    public function refreshYearLevel($duration)
    {
        if (!$this->date_accepted) return;
        $this->year_level = min(Carbon::parse($this->date_accepted)->diffInYears(now()), max(1, (int)$duration) - 1) + 1;
        $this->save();
    }
}

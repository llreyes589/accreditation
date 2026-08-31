<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Resident extends Model
{
    use SoftDeletes;
    protected $fillable = ['user_id', 'institution_id', 'track', 'date_accepted', 'expected_completion_date', 'age_at_enrollment', 'year_level', 'promotion_status', 'promotion_evaluated_at', 'completion_reviewed_at'];
    protected $casts = ['date_accepted' => 'date', 'expected_completion_date' => 'date', 'promotion_evaluated_at' => 'datetime', 'completion_reviewed_at' => 'datetime'];
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
    public function transfers()
    {
        return $this->hasMany(ResidentTransfer::class);
    }
    public function refreshYearLevel($duration)
    {
        if (!$this->date_accepted) return;
        $this->year_level = min(Carbon::parse($this->date_accepted)->diffInYears(now()), max(1, (int)$duration) - 1) + 1;
        $this->save();
    }

    /** Flowchart node R: manually advance the resident to the next year level. */
    public function advanceYear()
    {
        $this->year_level = ($this->year_level ?? 0) + 1;
        $this->save();
    }

    /** Flowchart node S: training officer reviews program completion. */
    public function markCompletionReviewed()
    {
        $this->completion_reviewed_at = now();
        $this->save();
    }
}

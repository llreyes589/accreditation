<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CorrectiveActionEvidence extends Model
{
    protected $fillable = ['corrective_action_id', 'file_path', 'original_name', 'uploaded_by'];

    public function action()
    {
        return $this->belongsTo(CorrectiveAction::class, 'corrective_action_id');
    }

    public function uploadedBy()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}

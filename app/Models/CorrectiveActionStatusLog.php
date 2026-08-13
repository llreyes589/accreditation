<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CorrectiveActionStatusLog extends Model
{
    public const CREATED_AT = null;
    public const UPDATED_AT = null;

    protected $fillable = ['corrective_action_id', 'status', 'comment', 'actor_id', 'logged_at'];
    protected $casts = ['logged_at' => 'datetime'];

    public function action()
    {
        return $this->belongsTo(CorrectiveAction::class, 'corrective_action_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}

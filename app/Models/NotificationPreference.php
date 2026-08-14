<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationPreference extends Model
{
    protected $fillable = [
        'user_id', 'category', 'channel', 'enabled',
        'quiet_hours_start', 'quiet_hours_end',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'quiet_hours_start' => 'string',
        'quiet_hours_end' => 'string',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** True if the current time falls inside this preference's quiet-hours window. */
    public function inQuietHours(?\DateTimeInterface $now = null): bool
    {
        if (!$this->quiet_hours_start || !$this->quiet_hours_end) {
            return false;
        }
        $now = $now ?: now();
        $start = (int) substr((string) $this->quiet_hours_start, 0, 2);
        $end = (int) substr((string) $this->quiet_hours_end, 0, 2);
        $hour = (int) $now->format('G');
        if ($start <= $end) {
            return $hour >= $start && $hour < $end;
        }
        // Window crosses midnight (e.g. 22 -> 07).
        return $hour >= $start || $hour < $end;
    }
}

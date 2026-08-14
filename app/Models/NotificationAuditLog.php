<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationAuditLog extends Model
{
    // Metadata-only audit; no updated_at column.
    public const UPDATED_AT = null;

    public const EVENT_DISPATCHED = 'dispatched';
    public const EVENT_SKIPPED = 'skipped';
    public const EVENT_DEFERRED = 'deferred';
    public const EVENT_READ = 'read';

    protected $fillable = [
        'user_id', 'notification_type', 'channel', 'event', 'reason', 'occurred_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function record(
        string $event,
        string $notificationType,
        string $channel,
        ?int $userId = null,
        ?string $reason = null
    ): self {
        return static::create([
            'event' => $event,
            'notification_type' => $notificationType,
            'channel' => $channel,
            'user_id' => $userId,
            'reason' => $reason,
            'occurred_at' => now(),
        ]);
    }
}

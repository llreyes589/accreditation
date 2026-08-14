<?php

namespace App\Services;

use App\Models\NotificationAuditLog;
use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * Single dispatch entry point for all notifications.
 *
 * Enforces the agent rules:
 *  - Respect user opt-out (per category + channel).
 *  - Respect quiet hours (defer non-urgent; urgent bypasses).
 *  - Record an audit entry for every dispatch / skip / defer.
 *
 * Callers MUST invoke this from within DB::afterCommit() so notifications fire
 * only after the originating transaction commits.
 */
class NotificationService
{
    /** Channels the system supports, mapped to Laravel's Notification::via() values. */
    private const VIA_MAP = [
        'database' => 'database',
        'in_app' => 'database',
        'email' => 'mail',
    ];

    /**
     * @param User          $user        Recipient.
     * @param Notification  $notification The notification instance (must expose $deliverChannels).
     * @param string        $category    Category key, e.g. 'accreditation_expiry'.
     * @param string[]      $channels     Desired channels (database|email|in_app).
     * @param bool          $urgent      If true, bypasses quiet hours (still respects opt-out).
     * @param \DateTimeInterface|null $now Override "now" (for tests).
     */
    public function notify(
        User $user,
        Notification $notification,
        string $category,
        array $channels = ['database', 'email'],
        bool $urgent = false,
        ?\DateTimeInterface $now = null
    ): void {
        $effective = [];

        foreach ($channels as $channel) {
            if ($user->hasOptedOut($category, $channel)) {
                NotificationAuditLog::record(
                    NotificationAuditLog::EVENT_SKIPPED,
                    $category,
                    $channel,
                    $user->id,
                    'opt_out'
                );
                continue;
            }

            if (!$urgent && $user->inQuietHours($category, $now)) {
                NotificationAuditLog::record(
                    NotificationAuditLog::EVENT_DEFERRED,
                    $category,
                    $channel,
                    $user->id,
                    'quiet_hours'
                );
                continue;
            }

            $via = self::VIA_MAP[$channel] ?? 'database';
            $effective[] = $via;
        }

        if (empty($effective)) {
            return;
        }

        // De-duplicate while preserving order.
        $effective = array_values(array_unique($effective));

        if (method_exists($notification, 'setChannels')) {
            $notification->setChannels($effective);
        }

        $user->notify($notification);

        foreach ($effective as $via) {
            $channelName = array_search($via, self::VIA_MAP, true) ?: $via;
            NotificationAuditLog::record(
                NotificationAuditLog::EVENT_DISPATCHED,
                $category,
                $channelName,
                $user->id
            );
        }
    }

    /** Record a "read" audit event (called when a user views a notification). */
    public static function recordRead(int $userId, string $category = 'in_app'): void
    {
        NotificationAuditLog::record(
            NotificationAuditLog::EVENT_READ,
            $category,
            'database',
            $userId
        );
    }

    /**
     * Resolve recipients for an institution: its training officers + PSP/CART reviewers.
     * @return \Illuminate\Support\Collection<int, User>
     */
    public static function institutionRecipients(\App\Models\Institution $institution): \Illuminate\Support\Collection
    {
        $users = \App\Models\TrainingOfficer::where('institution_id', $institution->id)
            ->with('user')->get()->pluck('user')->filter();
        $reviewers = \App\Models\User::whereHas('roles', fn ($q) => $q->whereIn('name', ['Admin', 'Accreditor']))->get();
        return $users->concat($reviewers)->unique('id');
    }
}

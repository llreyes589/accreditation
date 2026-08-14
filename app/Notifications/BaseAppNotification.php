<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Base for all app notifications. Channel delivery is driven by
 * NotificationService, which calls setChannels() after applying the
 * recipient's opt-out / quiet-hours rules.
 */
abstract class BaseAppNotification extends Notification
{
    use Queueable;

    /** @var string[] Laravel via() channels (database|mail). */
    public array $deliverChannels = ['database', 'mail'];

    public function setChannels(array $channels): self
    {
        $this->deliverChannels = $channels;
        return $this;
    }

    public function via($notifiable): array
    {
        return $this->deliverChannels;
    }
}

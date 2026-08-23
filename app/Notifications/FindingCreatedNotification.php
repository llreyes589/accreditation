<?php

namespace App\Notifications;

use App\Models\Finding;

class FindingCreatedNotification extends BaseAppNotification
{
    private Finding $finding;
    public function __construct(Finding $finding)
    {
        $this->finding = $finding;
    }

    public function toMail($notifiable)
    {
        return (new \Illuminate\Notifications\Messages\MailMessage)
            ->subject('New finding raised')
            ->line("A new finding was raised: {$this->finding->title}.");
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'finding_created',
            'finding_id' => $this->finding->id,
            'title' => $this->finding->title,
            'severity' => $this->finding->severity,
        ];
    }
}

<?php

namespace App\Notifications;

use App\Models\AccreditationInspection;

class InspectionScheduledReminder extends BaseAppNotification
{
    private AccreditationInspection $inspection;
    private ?int $daysRemaining = null;

    public function __construct(AccreditationInspection $inspection, ?int $daysRemaining = null)
    {
        $this->inspection = $inspection;
        $this->daysRemaining = $daysRemaining;
    }

    public function toMail($notifiable)
    {
        $when = $this->inspection->inspection_scheduled_at->toDateString() ?? 'soon';
        return (new \Illuminate\Notifications\Messages\MailMessage)
            ->subject('Inspection scheduled')
            ->line("An inspection is scheduled for {$when}.");
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'inspection_scheduled',
            'inspection_id' => $this->inspection->id,
            'accreditation_id' => $this->inspection->accreditation_id,
            'scheduled_at' => $this->inspection->inspection_scheduled_at->toDateString(),
        ];
    }
}

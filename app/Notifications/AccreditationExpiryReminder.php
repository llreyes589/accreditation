<?php

namespace App\Notifications;

use App\Models\Accreditation;

class AccreditationExpiryReminder extends BaseAppNotification
{
    private Accreditation $accreditation;
    private int $daysRemaining;

    public function __construct(Accreditation $accreditation, int $daysRemaining)
    {
        $this->accreditation = $accreditation;
        $this->daysRemaining = $daysRemaining;
    }

    public function toMail($notifiable)
    {
        return (new \Illuminate\Notifications\Messages\MailMessage)
            ->subject('Accreditation expiring soon')
            ->line("Accreditation for {$this->accreditation->institution->name} expires in {$this->daysRemaining} day(s) ({$this->accreditation->valid_until}).");
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'accreditation_expiry',
            'accreditation_id' => $this->accreditation->id,
            'institution_id' => $this->accreditation->institution_id,
            'valid_until' => $this->accreditation->valid_until->toDateString(),
            'days_remaining' => $this->daysRemaining,
        ];
    }
}

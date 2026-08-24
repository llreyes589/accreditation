<?php

namespace App\Notifications;

use App\Models\Accreditation;

class RenewalDueReminder extends BaseAppNotification
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
            ->subject('Renewal due soon')
            ->line("Renewal for {$this->accreditation->institution->name} is due in {$this->daysRemaining} day(s) ({$this->accreditation->valid_until}).");
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'renewal_due',
            'accreditation_id' => $this->accreditation->id,
            'institution_id' => $this->accreditation->institution_id,
            'valid_until' => $this->accreditation->valid_until->toDateString(),
            'days_remaining' => $this->daysRemaining,
        ];
    }
}

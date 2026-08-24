<?php

namespace App\Notifications;

use App\Models\Accreditation;

class DecisionIssuedNotification extends BaseAppNotification
{
    private Accreditation $accreditation;
    private string $outcome;

    public function __construct(Accreditation $accreditation, string $outcome)
    {
        $this->accreditation = $accreditation;
        $this->outcome = $outcome;
    }

    public function toMail($notifiable)
    {
        return (new \Illuminate\Notifications\Messages\MailMessage)
            ->subject('Accreditation decision issued')
            ->line("Decision for {$this->accreditation->institution->name}: {$this->outcome}.");
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'decision_issued',
            'accreditation_id' => $this->accreditation->id,
            'institution_id' => $this->accreditation->institution_id,
            'outcome' => $this->outcome,
        ];
    }
}

<?php

namespace App\Notifications;

use App\Models\Accreditation;

class StatusChangeNotification extends BaseAppNotification
{
    private string $event;
    private Accreditation $accreditation;
    private ?string $detail = null;

    public function __construct(string $event, Accreditation $accreditation, ?string $detail = null)
    {
        $this->event = $event;
        $this->accreditation = $accreditation;
        $this->detail = $detail;
    }

    public function toMail($notifiable)
    {
        return (new \Illuminate\Notifications\Messages\MailMessage)
            ->subject("Status update: {$this->event}")
            ->message("Accreditation for {$this->accreditation->institution->name}: {$this->event}.");
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'status_change',
            'event' => $this->event,
            'accreditation_id' => $this->accreditation->id,
            'institution_id' => $this->accreditation->institution_id,
            'detail' => $this->detail,
        ];
    }
}

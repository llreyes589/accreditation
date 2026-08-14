<?php

namespace App\Notifications;

use App\Models\CorrectiveAction;

class CorrectiveActionDueReminder extends BaseAppNotification
{
    public function __construct(
        private CorrectiveAction $action,
        private int $daysRemaining
    ) {}

    public function toMail($notifiable)
    {
        $when = $this->daysRemaining < 0
            ? abs($this->daysRemaining) . ' day(s) overdue'
            : "due in {$this->daysRemaining} day(s)";
        return (new \Illuminate\Notifications\Messages\MailMessage)
            ->subject('Corrective action deadline')
            ->line("Corrective action #{$this->action->id} is {$when}.");
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'corrective_action_due',
            'corrective_action_id' => $this->action->id,
            'due_date' => $this->action->due_date->toDateString(),
            'days_remaining' => $this->daysRemaining,
        ];
    }
}

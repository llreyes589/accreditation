<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LicenseExpiryReminder extends Notification
{
    use Queueable;
    private $document;
    public function __construct($document)
    {
        $this->document = $document;
    }
    public function via($n)
    {
        return ['mail', 'database'];
    }
    public function toMail($n)
    {
        return (new MailMessage)->subject('Institution document expiry reminder')->line('Your ' . $this->document->type . ' document expires on ' . $this->document->expires_at->toDateString() . '.');
    }
    public function toArray($n)
    {
        return ['document_id' => $this->document->id, 'institution_id' => $this->document->institution_id, 'expires_at' => $this->document->expires_at->toDateString(), 'message' => 'Institution document expires soon.'];
    }
}

<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\InstitutionDocument;
use App\Notifications\LicenseExpiryReminder;

class CheckDocumentExpiry extends Command
{
    protected $signature = 'institutions:check-document-expiry';
    protected $description = 'Send December reminders for expiring institution documents';
    public function handle()
    {
        if (now()->month !== 12) return 0;
        // In non-production environments, skip sending email notifications (database-only).
        if (!app()->environment('production')) return 0;

        InstitutionDocument::whereYear('expires_at', now()->addYear()->year)->each(function ($doc) {
            $doc->institution->trainingOfficers()->with('user')->get()->each(function ($officer) use ($doc) {
                $officer->user->notify(new LicenseExpiryReminder($doc));
            });
        });
        return 0;
    }
}

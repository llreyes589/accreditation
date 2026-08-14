<?php

namespace App\Console\Commands;

use App\Models\{User, Role, Institution, TrainingOfficer, Accreditation, AccreditationInspection, Finding, CorrectiveAction, ChecklistItem, Setting, AccreditationDecision, NotificationAuditLog};
use Illuminate\Console\Command;

/**
 * Demo / test harness for the Notifications & Reports module.
 * Seeds fixtures (accreditation expiring soon, renewal due, corrective action due,
 * inspection scheduled) and runs the reminder scan, then prints counts.
 */
class NotificationsDemo extends Command
{
    protected $signature = 'notifications:demo';
    protected $description = 'Seed notification fixtures and run the reminder scan (test harness).';

    public function handle(): int
    {
        foreach (['Admin', 'Accreditor', 'TrainingOfficer', 'TrainingInstitution'] as $rn) {
            Role::firstOrCreate(['name' => $rn]);
        }

        // Lead-time settings (config-driven, not hard-coded).
        Setting::updateOrCreate(['key' => 'accreditation_expiry_lead_days'], ['value' => [30, 60, 90]]);
        Setting::updateOrCreate(['key' => 'renewal_due_lead_days'], ['value' => [30, 60, 90]]);
        Setting::updateOrCreate(['key' => 'corrective_action_due_lead_days'], ['value' => [3, 7, 14]]);
        Setting::updateOrCreate(['key' => 'inspection_reminder_days'], ['value' => [1, 3, 7]]);
        Setting::updateOrCreate(['key' => 'review_pending_threshold_days'], ['value' => 5]);

        $inst = Institution::create(['name' => 'Demo Inst ' . uniqid(), 'registration_status' => 'approved']);
        $to = User::create(['name' => 'Demo TO', 'username' => 'to_' . uniqid(), 'email' => uniqid() . '@x.ph', 'password' => bcrypt('password1'), 'status' => 'approved', 'email_verified_at' => now()]);
        $to->assignRole('TrainingOfficer');
        TrainingOfficer::create(['user_id' => $to->id, 'institution_id' => $inst->id]);
        $accr = User::create(['name' => 'Demo Accr', 'username' => 'accr_' . uniqid(), 'email' => uniqid() . '@x.ph', 'password' => bcrypt('password1'), 'status' => 'approved', 'email_verified_at' => now()]);
        $accr->assignRole('Accreditor');

        // Accreditation expiring in 30 days (hits the 30-day lead window).
        $acc = Accreditation::create([
            'institution_id' => $inst->id, 'status' => Accreditation::STATUS_APPROVED,
            'valid_from' => today()->subYear(), 'valid_until' => today()->addDays(30),
            'submission_type' => 'new', 'checklist_snapshot' => [],
        ]);
        // Renewal due in 60 days.
        $renew = Accreditation::create([
            'institution_id' => $inst->id, 'status' => Accreditation::STATUS_APPROVED,
            'valid_from' => today()->subYear(), 'valid_until' => today()->addDays(60),
            'submission_type' => 'renew', 'checklist_snapshot' => [],
        ]);
        // Inspection scheduled in 3 days.
        $insp = AccreditationInspection::create([
            'accreditation_id' => $acc->id, 'accreditor_id' => $accr->id,
            'inspection_scheduled_at' => today()->addDays(3), 'status' => AccreditationInspection::STATUS_PENDING,
            'answers' => [],
        ]);
        // Finding + corrective action due in 3 days.
        $item = ChecklistItem::first() ?? ChecklistItem::create(['label' => 'Demo item', 'sort_order' => 1, 'is_major' => false, 'criterion' => 'demo']);
        $finding = Finding::create([
            'accreditation_inspection_id' => $insp->id, 'checklist_item_id' => $item->id,
            'title' => 'Demo finding', 'description' => 'demo', 'severity' => 'minor',
            'status' => Finding::STATUS_OPEN, 'raised_by' => $accr->id,
        ]);
        CorrectiveAction::create([
            'finding_id' => $finding->id, 'action_plan' => 'Fix it', 'due_date' => today()->addDays(3),
            'status' => CorrectiveAction::STATUS_OPEN, 'created_by' => $to->id,
        ]);

        $this->info('Fixtures seeded. Running reminder scan...');
        $this->call(SendNotificationReminders::class);

        $this->info('In-app notifications for Training Officer: ' . $to->notifications()->count());
        $this->info('Audit log entries: ' . NotificationAuditLog::count());
        $this->info('  dispatched: ' . NotificationAuditLog::where('event', 'dispatched')->count());
        $this->info('  skipped:    ' . NotificationAuditLog::where('event', 'skipped')->count());
        $this->info('  deferred:   ' . NotificationAuditLog::where('event', 'deferred')->count());

        return self::SUCCESS;
    }
}

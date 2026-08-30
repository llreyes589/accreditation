<?php

namespace App\Console\Commands;

use App\Models\{Accreditation, AccreditationInspection, CorrectiveAction, Finding, Institution, TrainingOfficer, User, Setting};
use App\Notifications\{AccreditationExpiryReminder, RenewalDueReminder, CorrectiveActionDueReminder, InspectionScheduledReminder, FindingCreatedNotification};
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class SendNotificationReminders extends Command
{
    protected $signature = 'notifications:send-reminders';
    protected $description = 'Dispatch deadline reminders (accreditation expiry, renewal, corrective-action due, inspection, findings awaiting response).';

    public function handle(): int
    {
        $svc = new NotificationService();
        $now = today();
        $autoApprove = !app()->environment('production');
        $channels = $autoApprove ? ['database'] : ['database', 'email'];

        $expiryLeads = $this->leadDays('accreditation_expiry_lead_days', [30, 60, 90]);
        $renewalLeads = $this->leadDays('renewal_due_lead_days', [30, 60, 90]);
        $caLeads = $this->leadDays('corrective_action_due_lead_days', [3, 7, 14]);
        $inspLeads = $this->leadDays('inspection_reminder_days', [1, 3, 7]);
        $reviewThreshold = (int) Setting::getValue('review_pending_threshold_days', 5);

        // 1) Accreditation expiry (approved/probationary, valid_until within lead window).
        foreach (Accreditation::whereIn('status', [Accreditation::STATUS_APPROVED, Accreditation::STATUS_PROBATIONARY])->get() as $acc) {
            if (!$acc->valid_until) continue;
            $days = (int) $now->diffInDays($acc->valid_until, false);
            if ($days < 0 || !in_array($days, $expiryLeads, true)) continue;
            foreach ($this->institutionRecipients($acc->institution) as $user) {
                $svc->notify($user, new AccreditationExpiryReminder($acc, $days), 'deadline_reminder', $channels);
            }
        }

        // 2) Renewal due (submission_type = renew, valid_until within lead window).
        foreach (Accreditation::where('submission_type', 'renew')->whereIn('status', [Accreditation::STATUS_APPROVED, Accreditation::STATUS_PROBATIONARY])->get() as $acc) {
            if (!$acc->valid_until) continue;
            $days = (int) $now->diffInDays($acc->valid_until, false);
            if ($days < 0 || !in_array($days, $renewalLeads, true)) continue;
            foreach ($this->institutionRecipients($acc->institution) as $user) {
                $svc->notify($user, new RenewalDueReminder($acc, $days), 'deadline_reminder', $channels);
            }
        }

        // 3) Corrective-action due / overdue.
        foreach (CorrectiveAction::whereNotNull('due_date')->whereNotIn('status', ['resolved', 'verified'])->get() as $action) {
            $days = (int) $now->diffInDays($action->due_date, false);
            if ($days >= 0 && !in_array($days, $caLeads, true)) continue;
            $recipients = new Collection();
            if ($action->assigned_to) $recipients->push(User::find($action->assigned_to));
            if ($action->finding->institution()) {
                $recipients = $recipients->concat($this->institutionRecipients($action->finding->institution()->first()));
            }
            foreach ($recipients->filter() as $user) {
                $svc->notify($user, new CorrectiveActionDueReminder($action, $days), 'deadline_reminder', $channels);
            }
        }

        // 4) Inspection scheduled within lead window.
        foreach (AccreditationInspection::where('status', AccreditationInspection::STATUS_PENDING)->whereNotNull('inspection_scheduled_at')->get() as $insp) {
            $days = (int) $now->diffInDays($insp->inspection_scheduled_at, false);
            if ($days < 0 || !in_array($days, $inspLeads, true)) continue;
            $recipients = new Collection();
            if ($insp->accreditor_id) $recipients->push(User::find($insp->accreditor_id));
            if ($insp->accreditation->institution) {
                $recipients = $recipients->concat($this->institutionRecipients($insp->accreditation->institution));
            }
            foreach ($recipients->filter() as $user) {
                $svc->notify($user, new InspectionScheduledReminder($insp, $days), 'deadline_reminder', $channels);
            }
        }

        // 5) Findings awaiting response (open > threshold days).
        foreach (Finding::where('status', Finding::STATUS_OPEN)->get() as $finding) {
            $created = $finding->created_at ?: $now;
            if ((int) $created->diffInDays($now) < $reviewThreshold) continue;
            if ($inst = $finding->institution()->first()) {
                foreach ($this->institutionRecipients($inst) as $user) {
                    $svc->notify($user, new FindingCreatedNotification($finding), 'status_change', $channels);
                }
            }
        }

        $this->info('Reminder scan complete.');
        return self::SUCCESS;
    }

    /** Institution training officers + PSP/CART reviewers (Admin/Accreditor). */
    private function institutionRecipients(?Institution $institution): Collection
    {
        if (!$institution) return new Collection();
        $users = TrainingOfficer::where('institution_id', $institution->id)
            ->with('user')->get()->pluck('user')->filter();
        $reviewers = User::whereHas('roles', fn($q) => $q->whereIn('name', ['Admin', 'Accreditor']))->get();
        return $users->concat($reviewers)->unique('id');
    }

    /** Lead-time array from settings; falls back to provided default. */
    private function leadDays(string $key, array $default): array
    {
        $v = Setting::getValue($key);
        if (is_array($v) && !empty($v)) return array_map('intval', $v);
        if (is_numeric($v)) return [intval($v)];
        return $default;
    }
}

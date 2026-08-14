<?php

namespace Tests\Feature;

use App\Models\{User, Role, Institution, TrainingOfficer, Accreditation, CorrectiveAction, Finding, AccreditationInspection, ChecklistItem, Setting, NotificationPreference, NotificationAuditLog, AccreditationDecision};
use App\Notifications\{AccreditationExpiryReminder, FindingCreatedNotification};
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NotificationsReportsTest extends TestCase
{
    use RefreshDatabase;

    private function roles(): void
    {
        foreach (['Admin', 'Accreditor', 'TrainingOfficer', 'TrainingInstitution'] as $r) {
            Role::firstOrCreate(['name' => $r]);
        }
    }

    private function makeInstitutionWithTO()
    {
        $inst = Institution::create(['name' => 'I' . uniqid(), 'registration_status' => 'approved']);
        $to = User::create(['name' => 'TO', 'username' => 'to_' . uniqid(), 'email' => uniqid() . '@x.ph', 'password' => bcrypt('password1'), 'status' => 'approved', 'email_verified_at' => now()]);
        $to->assignRole('TrainingOfficer');
        TrainingOfficer::create(['user_id' => $to->id, 'institution_id' => $inst->id]);
        return [$inst, $to];
    }

    private function token($role)
    {
        $u = User::create(['name' => $role, 'username' => $role . uniqid(), 'email' => uniqid() . '@x.ph', 'password' => bcrypt('password1'), 'status' => 'approved', 'email_verified_at' => now()]);
        $u->assignRole($role);
        return $u->createToken('t')->plainTextToken;
    }

    public function test_deadline_reminder_dispatched_by_command()
    {
        Mail::fake();
        $this->roles();
        Setting::updateOrCreate(['key' => 'accreditation_expiry_lead_days'], ['value' => [30, 60, 90]]);
        [$inst, $to] = $this->makeInstitutionWithTO();
        Accreditation::create([
            'institution_id' => $inst->id, 'status' => 'approved',
            'valid_from' => today()->subYear(), 'valid_until' => today()->addDays(30),
            'submission_type' => 'new', 'checklist_snapshot' => [],
        ]);

        $this->artisan('notifications:send-reminders')->assertSuccessful();

        $this->assertGreaterThan(0, $to->notifications()->count());
        $this->assertDatabaseHas('notification_audit_logs', ['event' => 'dispatched', 'notification_type' => 'deadline_reminder']);
    }

    public function test_status_change_notification_to_institution_recipient()
    {
        Mail::fake();
        $this->roles();
        [$inst, $to] = $this->makeInstitutionWithTO();
        $accr = User::create(['name' => 'A', 'username' => 'a_' . uniqid(), 'email' => uniqid() . '@x.ph', 'password' => bcrypt('password1'), 'status' => 'approved', 'email_verified_at' => now()]);
        $accr->assignRole('Accreditor');
        $acc = Accreditation::create(['institution_id' => $inst->id, 'status' => 'inspected', 'checklist_snapshot' => []]);
        $insp = AccreditationInspection::create(['accreditation_id' => $acc->id, 'accreditor_id' => $accr->id, 'status' => 'submitted', 'answers' => []]);
        $item = ChecklistItem::first() ?? ChecklistItem::create(['label' => 'x', 'sort_order' => 1, 'criterion' => 'x']);
        $finding = Finding::create(['accreditation_inspection_id' => $insp->id, 'checklist_item_id' => $item->id, 'title' => 't', 'description' => 'd', 'severity' => 'minor', 'status' => 'open', 'raised_by' => $accr->id]);

        $svc = new NotificationService();
        foreach (NotificationService::institutionRecipients($inst) as $recipient) {
            $svc->notify($recipient, new FindingCreatedNotification($finding), 'status_change', ['database', 'email']);
        }

        $this->assertGreaterThan(0, $to->notifications()->count());
    }

    public function test_opt_out_prevents_dispatch()
    {
        Mail::fake();
        $this->roles();
        [$inst, $to] = $this->makeInstitutionWithTO();
        NotificationPreference::create(['user_id' => $to->id, 'category' => 'status_change', 'channel' => 'database', 'enabled' => false]);
        $accr = User::create(['name' => 'A', 'username' => 'a_' . uniqid(), 'email' => uniqid() . '@x.ph', 'password' => bcrypt('password1'), 'status' => 'approved', 'email_verified_at' => now()]);
        $accr->assignRole('Accreditor');
        $acc = Accreditation::create(['institution_id' => $inst->id, 'status' => 'inspected', 'checklist_snapshot' => []]);
        $insp = AccreditationInspection::create(['accreditation_id' => $acc->id, 'accreditor_id' => $accr->id, 'status' => 'submitted', 'answers' => []]);
        $finding = Finding::create(['accreditation_inspection_id' => $insp->id, 'title' => 't', 'description' => 'd', 'severity' => 'minor', 'status' => 'open', 'raised_by' => $to->id]);

        $svc = new NotificationService();
        $svc->notify($to, new FindingCreatedNotification($finding), 'status_change', ['database', 'email']);

        $this->assertEquals(0, $to->notifications()->count());
        $this->assertDatabaseHas('notification_audit_logs', ['event' => 'skipped', 'reason' => 'opt_out', 'user_id' => $to->id]);
    }

    public function test_quiet_hours_defer_non_urgent()
    {
        Mail::fake();
        $this->roles();
        [$inst, $to] = $this->makeInstitutionWithTO();
        $hour = (int) now()->format('G');
        NotificationPreference::create([
            'user_id' => $to->id, 'category' => 'deadline_reminder', 'channel' => 'database',
            'quiet_hours_start' => sprintf('%02d:00:00', $hour), 'quiet_hours_end' => sprintf('%02d:00:00', ($hour + 2) % 24),
        ]);
        $acc = Accreditation::create(['institution_id' => $inst->id, 'status' => 'approved', 'valid_until' => today()->addDays(30), 'submission_type' => 'new', 'checklist_snapshot' => []]);

        $svc = new NotificationService();
        $svc->notify($to, new AccreditationExpiryReminder($acc, 30), 'deadline_reminder', ['database', 'email'], false);

        $this->assertEquals(0, $to->notifications()->count());
        $this->assertDatabaseHas('notification_audit_logs', ['event' => 'deferred', 'reason' => 'quiet_hours', 'user_id' => $to->id]);
    }

    public function test_urgent_bypasses_quiet_hours()
    {
        Mail::fake();
        $this->roles();
        [$inst, $to] = $this->makeInstitutionWithTO();
        $hour = (int) now()->format('G');
        NotificationPreference::create([
            'user_id' => $to->id, 'category' => 'system', 'channel' => 'database',
            'quiet_hours_start' => sprintf('%02d:00:00', $hour), 'quiet_hours_end' => sprintf('%02d:00:00', ($hour + 2) % 24),
        ]);
        $acc = Accreditation::create(['institution_id' => $inst->id, 'status' => 'approved', 'valid_until' => today()->addDays(30), 'submission_type' => 'new', 'checklist_snapshot' => []]);

        $svc = new NotificationService();
        // Urgent: still respects opt-out but bypasses quiet hours.
        $svc->notify($to, new AccreditationExpiryReminder($acc, 30), 'system', ['database', 'email'], true);

        $this->assertGreaterThan(0, $to->notifications()->count());
    }

    public function test_report_csv_with_filters_and_role_scope()
    {
        $this->roles();
        Setting::updateOrCreate(['key' => 'accreditation_years'], ['value' => 1]);
        [$inst, $to] = $this->makeInstitutionWithTO();
        $acc = Accreditation::create(['institution_id' => $inst->id, 'status' => 'approved', 'valid_from' => today(), 'valid_until' => today()->addYear(), 'submission_type' => 'new', 'checklist_snapshot' => []]);
        AccreditationDecision::create(['accreditation_id' => $acc->id, 'outcome' => 'approved', 'decided_by' => $to->id]);

        $admin = User::create(['name' => 'Adm', 'username' => 'adm_' . uniqid(), 'email' => uniqid() . '@x.ph', 'password' => bcrypt('password1'), 'status' => 'approved', 'email_verified_at' => now()]);
        $admin->assignRole('Admin');
        $res = $this->actingAs($admin)->get("/api/reports/accreditations?institution_id={$inst->id}");
        $res->assertStatus(200);
        $this->assertStringContainsString('text/csv', $res->headers->get('content-type'));

        // Institution user scoped to own only.
        $this->actingAs($to)->get("/api/reports/accreditations")->assertStatus(200);
        // Cross-institution forbidden.
        $other = Institution::create(['name' => 'Other' . uniqid(), 'registration_status' => 'approved']);
        $this->actingAs($to)->get("/api/reports/accreditations?institution_id={$other->id}")->assertStatus(403);
    }

    public function test_mark_read_records_audit()
    {
        $this->roles();
        [$inst, $to] = $this->makeInstitutionWithTO();
        $note = $to->notifications()->create(['id' => \Illuminate\Support\Str::uuid()->toString(), 'type' => 'App\\Notifications\\FindingCreatedNotification', 'data' => json_encode(['x' => 1]), 'notifiable_type' => User::class, 'notifiable_id' => $to->id]);

        $tO = $to->createToken('t')->plainTextToken;
        $this->withToken($tO)->postJson("/api/notifications/{$note->id}/read")->assertStatus(200);
        $this->assertDatabaseHas('notification_audit_logs', ['event' => 'read', 'user_id' => $to->id]);
    }

    public function test_audit_captures_dispatch_event()
    {
        Mail::fake();
        $this->roles();
        [$inst, $to] = $this->makeInstitutionWithTO();
        $acc = Accreditation::create(['institution_id' => $inst->id, 'status' => 'approved', 'valid_until' => today()->addDays(30), 'submission_type' => 'new', 'checklist_snapshot' => []]);

        $svc = new NotificationService();
        $svc->notify($to, new AccreditationExpiryReminder($acc, 30), 'deadline_reminder', ['database', 'email']);

        $this->assertDatabaseHas('notification_audit_logs', ['event' => 'dispatched', 'notification_type' => 'deadline_reminder', 'user_id' => $to->id]);
    }

    public function test_preferences_update_and_read()
    {
        $this->roles();
        [$inst, $to] = $this->makeInstitutionWithTO();
        $tO = $to->createToken('t')->plainTextToken;
        $this->withToken($tO)->putJson('/api/notification-preferences', [
            'preferences' => [['category' => 'deadline_reminder', 'channel' => 'email', 'enabled' => false]],
        ])->assertStatus(200);
        $this->assertTrue($to->hasOptedOut('deadline_reminder', 'email'));
    }
}

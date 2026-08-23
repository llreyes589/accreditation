<?php

namespace Tests\Feature;

use App\Models\{Accreditation, AccreditationInspection, InspectionAccreditor, Institution, Role, User};
use App\Services\InspectionAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InspectionAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Role::firstOrCreate(['name' => 'Admin']);
        $u = User::create(['name' => 'Admin', 'username' => 'admin_' . uniqid(), 'email' => uniqid() . '@x.ph', 'password' => bcrypt('password1'), 'status' => 'approved', 'email_verified_at' => now()]);
        $u->assignRole('Admin');
        return $u;
    }

    private function accreditor(): User
    {
        Role::firstOrCreate(['name' => 'Accreditor']);
        $a = User::create(['name' => 'Accreditor', 'username' => 'accr_' . uniqid(), 'email' => uniqid() . '@x.ph', 'password' => bcrypt('password1'), 'status' => 'approved', 'email_verified_at' => now()]);
        $a->assignRole('Accreditor');
        return $a;
    }

    private function scheduledInspection(User $admin): array
    {
        Role::firstOrCreate(['name' => 'TrainingInstitution']);
        $inst = Institution::create(['name' => 'Inst ' . uniqid(), 'registration_status' => 'approved']);
        $acc = $inst->accreditations()->create(['checklist_snapshot' => [], 'status' => Accreditation::STATUS_REQUIREMENTS_COMPLETED, 'submission_type' => 'new']);
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/accreditations/{$acc->id}/schedule-inspection", ['inspection_scheduled_at' => now()->addDays(5)->toDateString()])
            ->assertStatus(200);
        $acc->refresh();
        return [$acc, $acc->inspections()->where('status', AccreditationInspection::STATUS_PENDING)->firstOrFail()];
    }

    public function test_schedule_creates_pending_inspection_row(): void
    {
        $admin = $this->admin();
        [$acc, $inspection] = $this->scheduledInspection($admin);
        $this->assertNotNull($inspection);
        $this->assertSame(AccreditationInspection::STATUS_PENDING, $inspection->status);
    }

    public function test_assign_accreditor_at_schedule_time(): void
    {
        $admin = $this->admin();
        $lead = $this->accreditor();
        $member = $this->accreditor();
        Role::firstOrCreate(['name' => 'TrainingInstitution']);
        $inst = Institution::create(['name' => 'Inst ' . uniqid(), 'registration_status' => 'approved']);
        $acc = $inst->accreditations()->create(['checklist_snapshot' => [], 'status' => Accreditation::STATUS_REQUIREMENTS_COMPLETED, 'submission_type' => 'new']);

        $res = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/accreditations/{$acc->id}/schedule-inspection", [
                'inspection_scheduled_at' => now()->addDays(5)->toDateString(),
                'accreditor_ids' => [$member->id],
                'lead_id' => $lead->id,
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('accreditation_inspection_accreditors', [
            'user_id' => $lead->id, 'role' => InspectionAccreditor::ROLE_LEAD, 'status' => InspectionAccreditor::STATUS_INVITED,
        ]);
        $this->assertDatabaseHas('accreditation_inspection_accreditors', [
            'user_id' => $member->id, 'role' => InspectionAccreditor::ROLE_MEMBER,
        ]);
    }

    public function test_assign_accreditor_after_scheduling(): void
    {
        $admin = $this->admin();
        $accreditor = $this->accreditor();
        [$acc, $inspection] = $this->scheduledInspection($admin);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/accreditations/{$acc->id}/inspections/{$inspection->id}/accreditors", [
                'user_id' => $accreditor->id, 'role' => 'lead',
            ])
            ->assertStatus(201)
            ->assertJsonPath('accreditors.0.pivot.role', 'lead');

        $this->assertDatabaseHas('accreditation_inspection_accreditors', [
            'user_id' => $accreditor->id, 'role' => 'lead',
        ]);
    }

    public function test_change_lead_accreditor(): void
    {
        $admin = $this->admin();
        $lead = $this->accreditor();
        $newLead = $this->accreditor();
        [$acc, $inspection] = $this->scheduledInspection($admin);
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/accreditations/{$acc->id}/inspections/{$inspection->id}/accreditors", ['user_id' => $lead->id, 'role' => 'lead'])
            ->assertStatus(201);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/accreditations/{$acc->id}/inspections/{$inspection->id}/lead", ['user_id' => $newLead->id])
            ->assertStatus(200);

        $this->assertDatabaseHas('accreditation_inspection_accreditors', ['user_id' => $newLead->id, 'role' => 'lead']);
        $this->assertDatabaseHas('accreditation_inspection_accreditors', ['user_id' => $lead->id, 'role' => 'member']);
    }

    public function test_remove_accreditor(): void
    {
        $admin = $this->admin();
        $accreditor = $this->accreditor();
        [$acc, $inspection] = $this->scheduledInspection($admin);
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/accreditations/{$acc->id}/inspections/{$inspection->id}/accreditors", ['user_id' => $accreditor->id])
            ->assertStatus(201);
        $assignment = InspectionAccreditor::where('user_id', $accreditor->id)->firstOrFail();

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/admin/accreditations/{$acc->id}/inspections/{$inspection->id}/accreditors/{$accreditor->id}")
            ->assertStatus(200);

        $this->assertDatabaseHas('accreditation_inspection_accreditors', ['user_id' => $accreditor->id, 'status' => 'removed']);
    }

    public function test_daily_cap_blocks_fourth_same_day_inspection(): void
    {
        $admin = $this->admin();
        $accreditor = $this->accreditor();
        $day = now()->addDays(5);
        $service = new InspectionAssignmentService();

        // Three inspections same day -> ok.
        for ($i = 0; $i < 3; $i++) {
            $inst = Institution::create(['name' => 'Inst ' . uniqid(), 'registration_status' => 'approved']);
            $acc = $inst->accreditations()->create(['checklist_snapshot' => [], 'status' => Accreditation::STATUS_REQUIREMENTS_COMPLETED, 'submission_type' => 'new']);
            $inspection = $acc->inspections()->create([
                'status' => AccreditationInspection::STATUS_PENDING,
                'inspection_scheduled_at' => $day->toDateString(),
            ]);
            $service->assign($inspection, $accreditor, InspectionAccreditor::ROLE_MEMBER, $inspection->id);
        }

        // Fourth same-day inspection -> blocked by the cap.
        $inst = Institution::create(['name' => 'Inst ' . uniqid(), 'registration_status' => 'approved']);
        $acc = $inst->accreditations()->create(['checklist_snapshot' => [], 'status' => Accreditation::STATUS_REQUIREMENTS_COMPLETED, 'submission_type' => 'new']);
        $inspection = $acc->inspections()->create([
            'status' => AccreditationInspection::STATUS_PENDING,
            'inspection_scheduled_at' => $day->toDateString(),
        ]);

        $this->expectException(\App\Exceptions\InspectionAssignmentException::class);
        $service->assign($inspection, $accreditor, InspectionAccreditor::ROLE_MEMBER, $inspection->id);
    }

    public function test_non_admin_cannot_assign_accreditor(): void
    {
        $admin = $this->admin();
        $accreditor = $this->accreditor();
        [$acc, $inspection] = $this->scheduledInspection($admin);

        $this->actingAs($accreditor, 'sanctum')
            ->postJson("/api/admin/accreditations/{$acc->id}/inspections/{$inspection->id}/accreditors", ['user_id' => $accreditor->id])
            ->assertStatus(403);
    }

    /** Mirrors the exact frontend flow: schedule -> open detail (empty) -> assign -> detail (populated) -> remove -> detail (removed). */
    public function test_frontend_detail_flow_shows_assigned_accreditors(): void
    {
        $admin = $this->admin();
        $lead = $this->accreditor();
        $member = $this->accreditor();
        [$acc, $inspection] = $this->scheduledInspection($admin);

        // Detail initially has no accreditors on the scheduled inspection.
        $detail = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/accreditations/{$acc->id}")
            ->assertStatus(200)
            ->json();
        $pending = collect($detail['accreditation']['inspections'])->firstWhere('status', AccreditationInspection::STATUS_PENDING);
        $this->assertEmpty($pending['accreditors']);

        // Assign a lead and a member via the same endpoints the client calls.
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/accreditations/{$acc->id}/inspections/{$inspection->id}/accreditors", ['user_id' => $lead->id, 'role' => 'lead'])
            ->assertStatus(201);
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/accreditations/{$acc->id}/inspections/{$inspection->id}/accreditors", ['user_id' => $member->id, 'role' => 'member'])
            ->assertStatus(201);

        // Detail now shows both, with the correct roles (what the UI renders).
        $detail = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/accreditations/{$acc->id}")
            ->assertStatus(200)
            ->json();
        $pending = collect($detail['accreditation']['inspections'])->firstWhere('status', AccreditationInspection::STATUS_PENDING);
        $this->assertCount(2, $pending['accreditors']);
        $this->assertEquals('lead', collect($pending['accreditors'])->firstWhere('id', $lead->id)['pivot']['role']);
        $this->assertEquals('member', collect($pending['accreditors'])->firstWhere('id', $member->id)['pivot']['role']);

        // Remove the member via the endpoint the Remove button calls (by user id).
        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/admin/accreditations/{$acc->id}/inspections/{$inspection->id}/accreditors/{$member->id}")
            ->assertStatus(200);

        $detail = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/accreditations/{$acc->id}")
            ->assertStatus(200)
            ->json();
        $pending = collect($detail['accreditation']['inspections'])->firstWhere('status', AccreditationInspection::STATUS_PENDING);
        $this->assertCount(1, $pending['accreditors']);
        $this->assertEquals($lead->id, $pending['accreditors'][0]['id']);
    }
}

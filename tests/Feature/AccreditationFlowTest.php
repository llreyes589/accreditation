<?php

namespace Tests\Feature;

use App\Models\{Accreditation, Institution, Role, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccreditationFlowTest extends TestCase
{
    use RefreshDatabase;

    private function ownerWithInstitution(array $attrs = []): User
    {
        Role::firstOrCreate(['name' => 'TrainingInstitution']);
        $u = User::create([
            'name' => 'Owner', 'username' => 'owner_' . uniqid(), 'email' => uniqid() . '@x.ph',
            'password' => bcrypt('password1'), 'status' => 'approved', 'email_verified_at' => now(),
        ]);
        $u->assignRole('TrainingInstitution');
        $i = Institution::create(array_merge(['name' => 'Inst ' . uniqid(), 'registration_status' => 'approved'], $attrs));
        $i->user_id = $u->id;
        $i->save();
        return $u;
    }

    private function uploadDoc(User $u, string $type): void
    {
        Storage::fake('public');
        $this->actingAs($u, 'sanctum')
            ->postJson('/api/documents', ['type' => $type, 'file' => \Illuminate\Http\UploadedFile::fake()->create('doc.pdf', 10)])
            ->assertStatus(201);
    }

    public function test_submit_without_documents_is_rejected_with_missing_list(): void
    {
        $u = $this->ownerWithInstitution();
        $res = $this->actingAs($u, 'sanctum')
            ->postJson('/api/accreditations', ['checklist_snapshot' => [['label' => 'x', 'done' => false]]]);
        $res->assertStatus(422)
            ->assertJsonStructure(['message', 'missing_documents']);
        $this->assertCount(9, $res->json('missing_documents'));
    }

    public function test_submit_with_all_documents_creates_new_application(): void
    {
        $u = $this->ownerWithInstitution();
        foreach (Accreditation::REQUIRED_DOC_TYPES as $type) {
            $this->uploadDoc($u, $type);
        }
        $res = $this->actingAs($u, 'sanctum')
            ->postJson('/api/accreditations', ['checklist_snapshot' => [['label' => 'x', 'done' => false]]]);
        $res->assertStatus(201)
            ->assertJsonPath('submission_type', 'new')
            ->assertJsonPath('status', 'pending');
        $this->assertNotNull($res->json('submitted_at'));
    }

    public function test_submit_within_renewal_window_is_marked_renew(): void
    {
        $u = $this->ownerWithInstitution();
        foreach (Accreditation::REQUIRED_DOC_TYPES as $type) {
            $this->uploadDoc($u, $type);
        }
        // A rejected accreditation does NOT block a renewal; a still-valid approved one would.
        // Push the approved record into the past via a raw update (model mutators overwrite
        // created_at on every save), so the rejected record is unambiguously the latest.
        $appr = $u->institution()->first()->accreditations()->create([
            'checklist_snapshot' => [], 'status' => 'approved',
            'valid_from' => now()->subYear(), 'valid_until' => now()->addDays(30),
        ]);
        \Illuminate\Support\Facades\DB::table('accreditations')->where('id', $appr->id)
            ->update(['created_at' => now()->subDays(2)]);
        $u->institution()->first()->accreditations()->create([
            'checklist_snapshot' => [], 'status' => 'rejected',
            'valid_from' => now()->subYear(), 'valid_until' => now()->addDays(30),
        ]);
        $res = $this->actingAs($u, 'sanctum')
            ->postJson('/api/accreditations', ['checklist_snapshot' => [['label' => 'x', 'done' => false]]]);
        $res->assertStatus(201)->assertJsonPath('submission_type', 'renew');
    }

    public function test_submit_rejected_when_current_accreditation_still_valid(): void
    {
        $u = $this->ownerWithInstitution();
        foreach (Accreditation::REQUIRED_DOC_TYPES as $type) {
            $this->uploadDoc($u, $type);
        }
        // An approved, still-valid accreditation blocks a new/renew application.
        $u->institution()->first()->accreditations()->create([
            'checklist_snapshot' => [], 'status' => 'approved',
            'valid_from' => now()->subYear(), 'valid_until' => now()->addDays(30),
        ]);
        $res = $this->actingAs($u, 'sanctum')
            ->postJson('/api/accreditations', ['checklist_snapshot' => [['label' => 'x', 'done' => false]]]);
        $res->assertStatus(422);
    }

    public function test_admin_can_schedule_inspection(): void
    {
        Role::firstOrCreate(['name' => 'Admin']);
        $admin = User::create(['name' => 'Admin', 'username' => 'admin_' . uniqid(), 'email' => uniqid() . '@x.ph', 'password' => bcrypt('password1'), 'status' => 'approved', 'email_verified_at' => now()]);
        $admin->assignRole('Admin');
        $u = $this->ownerWithInstitution();
        foreach (Accreditation::REQUIRED_DOC_TYPES as $type) {
            $this->uploadDoc($u, $type);
        }
        $acc = $u->institution()->first()->accreditations()->create(['checklist_snapshot' => [['label' => 'x', 'done' => false]], 'status' => 'pending', 'submission_type' => 'new']);

        // Scheduling before requirements are marked complete is rejected.
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/accreditations/{$acc->id}/schedule-inspection", ['inspection_scheduled_at' => now()->addDays(10)->toDateString()])
            ->assertStatus(422);

        // Mark requirements complete, then schedule.
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/accreditations/{$acc->id}/mark-requirements-completed")
            ->assertStatus(200)->assertJsonPath('status', 'requirements_completed');
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/accreditations/{$acc->id}/schedule-inspection", ['inspection_scheduled_at' => now()->addDays(10)->toDateString()])
            ->assertStatus(200)
            ->assertJsonPath('status', 'inspection_scheduled');
        $this->assertDatabaseHas('accreditations', [
            'id' => $acc->id,
            'status' => 'inspection_scheduled',
            'inspection_scheduled_at' => now()->addDays(10)->toDateString(),
        ]);

        // past date rejected
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/accreditations/{$acc->id}/schedule-inspection", ['inspection_scheduled_at' => now()->subDays(1)->toDateString()])
            ->assertStatus(422);
    }

    private function makeAccreditor(): User
    {
        Role::firstOrCreate(['name' => 'Accreditor']);
        $a = User::create(['name' => 'Accreditor', 'username' => 'accr_' . uniqid(), 'email' => uniqid() . '@x.ph', 'password' => bcrypt('password1'), 'status' => 'approved', 'email_verified_at' => now()]);
        $a->assignRole('Accreditor');
        return $a;
    }

    public function test_mark_requirements_completed_gate(): void
    {
        Role::firstOrCreate(['name' => 'Admin']);
        $admin = User::create(['name' => 'Admin', 'username' => 'admin_' . uniqid(), 'email' => uniqid() . '@x.ph', 'password' => bcrypt('password1'), 'status' => 'approved', 'email_verified_at' => now()]);
        $admin->assignRole('Admin');
        $u = $this->ownerWithInstitution();
        foreach (Accreditation::REQUIRED_DOC_TYPES as $type) {
            $this->uploadDoc($u, $type);
        }
        $acc = $u->institution()->first()->accreditations()->create(['checklist_snapshot' => [], 'status' => 'pending', 'submission_type' => 'new']);

        // non-pending cannot be marked complete
        $acc->update(['status' => 'inspection_scheduled']);
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/accreditations/{$acc->id}/mark-requirements-completed")
            ->assertStatus(422);

        // pending -> requirements_completed
        $acc->update(['status' => 'pending']);
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/accreditations/{$acc->id}/mark-requirements-completed")
            ->assertStatus(200)->assertJsonPath('status', 'requirements_completed');
    }

    public function test_accreditor_submits_inspection_then_staff_approves(): void
    {
        $admin = User::create(['name' => 'Admin', 'username' => 'admin_' . uniqid(), 'email' => uniqid() . '@x.ph', 'password' => bcrypt('password1'), 'status' => 'approved', 'email_verified_at' => now()]);
        $admin->assignRole('Admin');
        $accreditor = $this->makeAccreditor();
        $u = $this->ownerWithInstitution();
        foreach (Accreditation::REQUIRED_DOC_TYPES as $type) {
            $this->uploadDoc($u, $type);
        }
        $acc = $u->institution()->first()->accreditations()->create(['checklist_snapshot' => [], 'status' => 'pending', 'submission_type' => 'new']);

        // admin: requirements complete -> schedule
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/accreditations/{$acc->id}/mark-requirements-completed")->assertStatus(200);
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/accreditations/{$acc->id}/schedule-inspection", ['inspection_scheduled_at' => now()->addDays(5)->toDateString()])->assertStatus(200);

        // checklist items exist; build full answers
        $items = \App\Models\ChecklistItem::all();
        $this->assertGreaterThan(0, $items->count());
        $answers = [];
        foreach ($items as $it) {
            $answers[$it->id] = ['compliant' => true, 'notes' => 'ok'];
        }

        // institution role cannot submit inspection (403)
        $this->actingAs($u, 'sanctum')
            ->postJson("/api/accreditor/accreditations/{$acc->id}/submit-inspection", ['answers' => $answers])
            ->assertStatus(403);

        // accreditor submits -> status inspected
        $res = $this->actingAs($accreditor, 'sanctum')
            ->postJson("/api/accreditor/accreditations/{$acc->id}/submit-inspection", ['answers' => $answers]);
        $res->assertStatus(200)->assertJsonPath('accreditation.status', 'inspected');
        $this->assertDatabaseHas('accreditations', ['id' => $acc->id, 'status' => 'inspected']);

        // missing items rejected
        $partial = $answers;
        array_pop($partial);
        $this->actingAs($accreditor, 'sanctum')
            ->postJson("/api/accreditor/accreditations/{$acc->id}/submit-inspection", ['answers' => $partial])
            ->assertStatus(422);

        // Accreditor (staff) approves the inspected accreditation
        $res = $this->actingAs($accreditor, 'sanctum')
            ->postJson("/api/staff/accreditations/{$acc->id}/approve");
        $res->assertStatus(200)->assertJsonPath('status', 'approved');
        $this->assertNotNull($res->json('valid_until'));
    }
}

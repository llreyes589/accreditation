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

        // Scheduling before approval is rejected.
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/accreditations/{$acc->id}/schedule-inspection", ['inspection_scheduled_at' => now()->addDays(10)->toDateString()])
            ->assertStatus(422);

        // Approve, then schedule.
        $acc->update(['status' => 'approved', 'approved_by' => $admin->id, 'valid_from' => now(), 'valid_until' => now()->addYear()]);
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
}

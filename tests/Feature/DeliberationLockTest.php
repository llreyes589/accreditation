<?php

namespace Tests\Feature;

use App\Models\{Accreditation, AccreditationInspection, ChecklistItem, Institution, Role, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliberationLockTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Role::firstOrCreate(['name' => 'Admin']);
        $u = User::create(['name' => 'Admin', 'username' => 'adm_' . uniqid(), 'email' => uniqid() . '@x.ph', 'password' => bcrypt('password1'), 'status' => 'approved', 'email_verified_at' => now()]);
        $u->assignRole('Admin');
        return $u;
    }

    private function accreditor(): User
    {
        Role::firstOrCreate(['name' => 'Accreditor']);
        $a = User::create(['name' => 'Accreditor', 'username' => 'acc_' . uniqid(), 'email' => uniqid() . '@x.ph', 'password' => bcrypt('password1'), 'status' => 'approved', 'email_verified_at' => now()]);
        $a->assignRole('Accreditor');
        return $a;
    }

    /** Build an inspected accreditation with a submitted inspection + one checklist item. */
    private function inspectedAccreditation(User $admin): array
    {
        Role::firstOrCreate(['name' => 'TrainingInstitution']);
        $inst = Institution::create(['name' => 'Inst ' . uniqid(), 'registration_status' => 'approved']);
        $acc = $inst->accreditations()->create([
            'checklist_snapshot' => [],
            'status' => Accreditation::STATUS_INSPECTED,
            'submission_type' => 'new',
        ]);
        $item = ChecklistItem::create(['section' => 'A', 'code' => 'A.1', 'criterion' => 'C1', 'sort_order' => 1]);
        $inspection = $acc->inspections()->create([
            'accreditor_id' => $this->accreditor()->id,
            'status' => AccreditationInspection::STATUS_SUBMITTED,
            'answers' => [(string) $item->id => ['compliant' => false, 'notes' => 'Missing']],
        ]);
        return [$acc, $inspection, $item];
    }

    /** t_4226d5b4: admin can move an inspected accreditation into deliberation. */
    public function test_admin_starts_deliberation(): void
    {
        $admin = $this->admin();
        [$acc] = $this->inspectedAccreditation($admin);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/accreditations/{$acc->id}/start-deliberation")
            ->assertStatus(200)
            ->assertJsonPath('status', Accreditation::STATUS_DELIBERATION);
    }

    /** t_4226d5b4: only an admin may edit the checklist during deliberation. */
    public function test_admin_edits_checklist_during_deliberation(): void
    {
        $admin = $this->admin();
        [$acc, $inspection, $item] = $this->inspectedAccreditation($admin);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/accreditations/{$acc->id}/start-deliberation")
            ->assertStatus(200);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/accreditations/{$acc->id}/checklist", [
                'answers' => [(string) $item->id => ['compliant' => true, 'notes' => 'Corrected in deliberation']],
            ])
            ->assertStatus(200);

        $inspection->refresh();
        $this->assertTrue($inspection->answers[(string) $item->id]['compliant'] === true);
    }

    /** t_4226d5b4: the accreditor is locked out of the checklist during deliberation. */
    public function test_accreditor_cannot_submit_during_deliberation(): void
    {
        $admin = $this->admin();
        $accreditor = $this->accreditor();
        [$acc] = $this->inspectedAccreditation($admin);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/accreditations/{$acc->id}/start-deliberation")
            ->assertStatus(200);

        $answers = [];
        foreach (ChecklistItem::pluck('id') as $cid) {
            $answers[(string) $cid] = ['compliant' => true];
        }

        // submitInspection only permits the `inspection_scheduled` status, so a
        // deliberation-phase accreditation is rejected (accreditor locked out).
        $this->actingAs($accreditor, 'sanctum')
            ->postJson("/api/accreditor/accreditations/{$acc->id}/submit-inspection", ['answers' => $answers])
            ->assertStatus(422);
    }

    /** t_4226d5b4: non-admin roles cannot edit the checklist during deliberation. */
    public function test_non_admin_cannot_edit_checklist_during_deliberation(): void
    {
        $admin = $this->admin();
        $accreditor = $this->accreditor();
        [$acc, $inspection, $item] = $this->inspectedAccreditation($admin);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/accreditations/{$acc->id}/start-deliberation")
            ->assertStatus(200);

        $this->actingAs($accreditor, 'sanctum')
            ->postJson("/api/admin/accreditations/{$acc->id}/checklist", [
                'answers' => [(string) $item->id => ['compliant' => true]],
            ])
            ->assertStatus(403);
    }
}

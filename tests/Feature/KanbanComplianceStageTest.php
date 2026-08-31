<?php

namespace Tests\Feature;

use App\Models\{Accreditation, AccreditationInspection, Finding, Institution, Role, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bug fix: an inspected accreditation application with outstanding (unresolved)
 * findings must be moved to the Compliance Kanban stage. Previously the
 * STAGE_OF map had no route to `compliance`, so the column was always empty.
 */
class KanbanComplianceStageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Role::firstOrCreate(['name' => 'Admin']);
        $u = User::create(['name' => 'Admin', 'username' => 'adm_' . uniqid(), 'email' => uniqid() . '@x.ph', 'password' => bcrypt('password1'), 'status' => 'approved', 'email_verified_at' => now()]);
        $u->assignRole('Admin');
        return $u;
    }

    private function inspectedAccreditation(): Accreditation
    {
        $inst = Institution::create(['name' => 'Inst ' . uniqid(), 'registration_status' => 'approved']);
        return Accreditation::create([
            'institution_id' => $inst->id,
            'status' => Accreditation::STATUS_INSPECTED,
            'submitted_at' => now(),
            'submission_type' => 'accreditation',
        ]);
    }

    private function addFinding(Accreditation $acc, string $status): Finding
    {
        $inspection = AccreditationInspection::create([
            'accreditation_id' => $acc->id,
            'status' => AccreditationInspection::STATUS_SUBMITTED,
            'answers' => [],
        ]);
        return Finding::create([
            'accreditation_inspection_id' => $inspection->id,
            'title' => 'Deficiency',
            'description' => 'Desc',
            'status' => $status,
            'raised_by' => $this->admin()->id,
        ]);
    }

    /** Inspected + outstanding finding -> Compliance stage. */
    public function test_inspected_with_open_finding_moves_to_compliance(): void
    {
        $acc = $this->inspectedAccreditation();
        $this->addFinding($acc, Finding::STATUS_OPEN);

        $res = $this->actingAs($this->admin(), 'sanctum')->getJson('/api/staff/kanban');
        $res->assertStatus(200);

        $compliance = collect($res->json('columns'))->firstWhere('stage.id', 'compliance');
        $ids = collect($compliance['applications'])->pluck('id')->all();
        $this->assertContains('ACC-' . $acc->id, $ids);
    }

    /** Inspected + only resolved/verified findings -> NOT Compliance (stays Inspection). */
    public function test_inspected_with_only_closed_findings_stays_in_inspection(): void
    {
        $acc = $this->inspectedAccreditation();
        $this->addFinding($acc, Finding::STATUS_RESOLVED);
        $this->addFinding($acc, Finding::STATUS_VERIFIED);

        $res = $this->actingAs($this->admin(), 'sanctum')->getJson('/api/staff/kanban');
        $res->assertStatus(200);

        $compliance = collect($res->json('columns'))->firstWhere('stage.id', 'compliance');
        $ids = collect($compliance['applications'])->pluck('id')->all();
        $this->assertNotContains('ACC-' . $acc->id, $ids);

        $inspection = collect($res->json('columns'))->firstWhere('stage.id', 'inspection');
        $this->assertContains('ACC-' . $acc->id, collect($inspection['applications'])->pluck('id')->all());
    }

    /** Resolving the last outstanding finding moves it out of Compliance. */
    public function test_resolving_finding_moves_out_of_compliance(): void
    {
        $acc = $this->inspectedAccreditation();
        $finding = $this->addFinding($acc, Finding::STATUS_OPEN);

        $before = $this->actingAs($this->admin(), 'sanctum')->getJson('/api/staff/kanban')->json('columns');
        $this->assertContains('ACC-' . $acc->id, collect(collect($before)->firstWhere('stage.id', 'compliance')['applications'])->pluck('id')->all());

        $finding->update(['status' => Finding::STATUS_RESOLVED]);

        $after = $this->actingAs($this->admin(), 'sanctum')->getJson('/api/staff/kanban')->json('columns');
        $this->assertNotContains('ACC-' . $acc->id, collect(collect($after)->firstWhere('stage.id', 'compliance')['applications'])->pluck('id')->all());
    }
}

<?php

namespace Tests\Feature;

use App\Models\{Institution, Resident, Role, User, TrainingOfficer, Consultant, RotationBlock, RotationAssignment, ResearchPaper, CaseLog, ConsultantReview, ConsultantEvaluation};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * t_05e5c5c7 — Verify the consultant / rotation / research / case-log modules are
 * deployed and functional (store + list end-to-end through the DomainController).
 * These modules had endpoints + UI but no dedicated test coverage.
 */
class ModuleDeploymentTest extends TestCase
{
    use RefreshDatabase;

    private function officerWithInstitution(): User
    {
        foreach (['TrainingInstitution', 'TrainingOfficer', 'Resident'] as $rn) {
            Role::firstOrCreate(['name' => $rn]);
        }
        $u = User::create(['name' => 'Officer', 'username' => 'off_' . uniqid(), 'email' => uniqid() . '@x.ph', 'password' => bcrypt('password1'), 'status' => 'approved', 'email_verified_at' => now()]);
        $u->assignRole('TrainingOfficer');
        $i = Institution::create(['name' => 'Inst ' . uniqid(), 'registration_status' => 'approved']);
        TrainingOfficer::create(['user_id' => $u->id, 'institution_id' => $i->id]);
        return $u;
    }

    private function makeResident(User $officer, string $track = 'AP'): Resident
    {
        $res = $this->actingAs($officer, 'sanctum')->postJson('/api/residents', [
            'name' => 'Res ' . uniqid(), 'username' => 'res_' . uniqid(),
            'email' => uniqid() . '@x.ph', 'password' => 'password1',
            'track' => $track, 'date_accepted' => now()->subYear()->toDateString(), 'age_at_enrollment' => 27,
        ]);
        return Resident::where('user_id', $res->json('id'))->first();
    }

    /** Consultant module: store + list. */
    public function test_consultant_store_and_list(): void
    {
        $officer = $this->officerWithInstitution();
        $this->actingAs($officer, 'sanctum')
            ->postJson('/api/consultants', ['name' => 'Dr. Consult', 'specialty' => 'AP', 'credentials' => 'DPATH'])
            ->assertStatus(201);
        $this->actingAs($officer, 'sanctum')->getJson('/api/consultants')->assertStatus(200)->assertJsonCount(1);
    }

    /** Rotation module: store rotation block + assign a resident + list. */
    public function test_rotation_store_assignment_and_list(): void
    {
        $officer = $this->officerWithInstitution();
        $resident = $this->makeResident($officer);
        $rot = $this->actingAs($officer, 'sanctum')->postJson('/api/rotations', [
            'title' => 'AP Rotation', 'category' => 'Anatomic Pathology',
            'starts_at' => now()->startOfMonth()->toDateString(),
            'ends_at' => now()->endOfMonth()->toDateString(),
        ])->assertStatus(201)->json('id');
        $this->actingAs($officer, 'sanctum')
            ->postJson("/api/rotations/{$rot}/assignments", ['resident_id' => $resident->id])
            ->assertStatus(201);
        $this->actingAs($officer, 'sanctum')->getJson('/api/rotations')->assertStatus(200);
    }

    /** Research module: store + list (scoped to institution residents). */
    public function test_research_store_and_list(): void
    {
        $officer = $this->officerWithInstitution();
        $resident = $this->makeResident($officer);
        $this->actingAs($officer, 'sanctum')
            ->postJson('/api/research-papers', ['resident_id' => $resident->id, 'title' => 'Paper', 'stage' => 'published'])
            ->assertStatus(201);
        $this->actingAs($officer, 'sanctum')->getJson('/api/research-papers')->assertStatus(200)->assertJsonCount(1);
    }

    /** Case-log module: store + list. */
    public function test_case_log_store_and_list(): void
    {
        $officer = $this->officerWithInstitution();
        $resident = $this->makeResident($officer);
        $this->actingAs($officer, 'sanctum')
            ->postJson('/api/case-logs', ['resident_id' => $resident->id, 'case_type' => 'Biopsy', 'procedure' => 'FFPE', 'count' => 12])
            ->assertStatus(201);
        $this->actingAs($officer, 'sanctum')->getJson('/api/case-logs')->assertStatus(200)->assertJsonCount(1);
    }

    /** Consultant review + evaluation (flowchart G/H/I, M) store + list. */
    public function test_consultant_review_and_evaluation(): void
    {
        $officer = $this->officerWithInstitution();
        $resident = $this->makeResident($officer);
        $consultant = Consultant::create(['institution_id' => $this->institution($officer)->id, 'name' => 'Dr. C', 'specialty' => 'AP']);
        $rot = RotationBlock::create(['institution_id' => $this->institution($officer)->id, 'title' => 'AP', 'category' => 'AP', 'starts_at' => now()->startOfMonth(), 'ends_at' => now()->endOfMonth()]);
        $assignment = RotationAssignment::create(['rotation_block_id' => $rot->id, 'resident_id' => $resident->id]);

        $this->actingAs($officer, 'sanctum')
            ->postJson('/api/consultant-reviews', ['rotation_assignment_id' => $assignment->id, 'consultant_id' => $consultant->id, 'status' => 'validated'])
            ->assertStatus(201);
        $this->actingAs($officer, 'sanctum')->getJson('/api/consultant-reviews')->assertStatus(200)->assertJsonCount(1);

        $this->actingAs($officer, 'sanctum')
            ->postJson('/api/consultant-evaluations', ['resident_id' => $resident->id, 'consultant_id' => $consultant->id, 'period' => '2026-Q1', 'recommendation' => 'continue'])
            ->assertStatus(201);
        $this->actingAs($officer, 'sanctum')->getJson('/api/consultant-evaluations')->assertStatus(200)->assertJsonCount(1);
    }

    private function institution(User $officer): Institution
    {
        return $officer->trainingOfficer->institution;
    }
}

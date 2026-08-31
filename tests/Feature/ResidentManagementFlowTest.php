<?php

namespace Tests\Feature;

use App\Models\{Institution, Resident, Role, User, TrainingOfficer, Quiz, QuizResult, Setting};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResidentManagementFlowTest extends TestCase
{
    use RefreshDatabase;

    /** Actor = Training Officer at an approved institution (the role that runs the resident flow). */
    private function officerWithInstitution(): User
    {
        foreach (['TrainingInstitution', 'TrainingOfficer', 'Resident', 'Consultant'] as $rn) {
            Role::firstOrCreate(['name' => $rn]);
        }
        $u = User::create([
            'name' => 'Officer', 'username' => 'off_' . uniqid(), 'email' => uniqid() . '@x.ph',
            'password' => bcrypt('password1'), 'status' => 'approved', 'email_verified_at' => now(),
        ]);
        $u->assignRole('TrainingOfficer');
        $i = Institution::create(['name' => 'Inst ' . uniqid(), 'registration_status' => 'approved']);
        TrainingOfficer::create(['user_id' => $u->id, 'institution_id' => $i->id]);
        return $u;
    }

    private function createResident(User $u, string $track = 'AP'): array
    {
        $res = $this->actingAs($u, 'sanctum')
            ->postJson('/api/residents', [
                'name' => 'Res ' . uniqid(), 'username' => 'res_' . uniqid(),
                'email' => uniqid() . '@x.ph', 'password' => 'password1',
                'track' => $track, 'date_accepted' => now()->subYear()->toDateString(), 'age_at_enrollment' => 27,
            ]);
        $userId = $res->json('id');
        $resident = Resident::where('user_id', $userId)->first();
        return [$res, $resident];
    }

    public function test_resident_profile_created_and_listed(): void
    {
        $u = $this->officerWithInstitution();
        [$res] = $this->createResident($u);
        $res->assertStatus(201)->assertJsonPath('status', 'pending');
        $this->actingAs($u, 'sanctum')->getJson('/api/residents')->assertStatus(200)->assertJsonCount(1);
    }

    /** Slice 1 (flowchart node C): year level + expected completion date persist at creation. */
    public function test_resident_year_level_and_completion_date_saved(): void
    {
        $u = $this->officerWithInstitution();
        $completion = now()->addYears(3)->toDateString();
        $res = $this->actingAs($u, 'sanctum')->postJson('/api/residents', [
            'name' => 'Res ' . uniqid(), 'username' => 'res_' . uniqid(),
            'email' => uniqid() . '@x.ph', 'password' => 'password1',
            'track' => 'AP', 'date_accepted' => now()->subYear()->toDateString(),
            'year_level' => 2, 'expected_completion_date' => $completion, 'age_at_enrollment' => 27,
        ]);
        $res->assertStatus(201);
        $resident = Resident::where('user_id', $res->json('id'))->first();
        $this->assertNotNull($resident);
        $this->assertEquals(2, $resident->year_level);
        $this->assertEquals($completion, $resident->expected_completion_date->toDateString());

        // Invalid year level is rejected.
        $bad = $this->actingAs($u, 'sanctum')->postJson('/api/residents', [
            'name' => 'Bad', 'username' => 'bad_' . uniqid(), 'email' => uniqid() . '@x.ph',
            'password' => 'password1', 'track' => 'AP', 'year_level' => 99,
        ]);
        $bad->assertStatus(422);
    }

    public function test_rotation_plan_created_and_assigned(): void
    {
        $u = $this->officerWithInstitution();
        [, $resident] = $this->createResident($u);

        $rot = $this->actingAs($u, 'sanctum')->postJson('/api/rotations', [
            'title' => 'AP Rotation 1', 'category' => 'Anatomic Pathology',
            'starts_at' => now()->startOfMonth()->toDateString(),
            'ends_at' => now()->endOfMonth()->toDateString(),
        ]);
        $rot->assertStatus(201);
        $rotId = $rot->json('id');

        $this->actingAs($u, 'sanctum')
            ->postJson("/api/rotations/{$rotId}/assignments", ['resident_id' => $resident->id])
            ->assertStatus(201);
        $this->actingAs($u, 'sanctum')->getJson('/api/rotations')->assertStatus(200);
    }

    public function test_case_log_and_research_recorded(): void
    {
        $u = $this->officerWithInstitution();
        [, $resident] = $this->createResident($u);

        $this->actingAs($u, 'sanctum')->postJson('/api/case-logs', [
            'resident_id' => $resident->id, 'case_type' => 'Biopsy', 'procedure' => 'FFPE', 'count' => 12,
        ])->assertStatus(201);
        $this->actingAs($u, 'sanctum')->getJson('/api/case-logs')->assertStatus(200);

        $this->actingAs($u, 'sanctum')->postJson('/api/research-papers', [
            'resident_id' => $resident->id, 'title' => 'Paper', 'stage' => 'published',
        ])->assertStatus(201);
        $this->actingAs($u, 'sanctum')->getJson('/api/research-papers')->assertStatus(200);
    }

    public function test_exam_result_recorded_and_promotion_evaluated(): void
    {
        $u = $this->officerWithInstitution();
        [, $resident] = $this->createResident($u);
        $resident->refreshYearLevel(3);
        $yl = $resident->year_level;

        $quiz = $this->actingAs($u, 'sanctum')->postJson('/api/quizzes', [
            'title' => 'RISE', 'type' => 'exam', 'max_score' => 100,
        ]);
        $quiz->assertStatus(201);
        $quizId = $quiz->json('id');

        // Promotion threshold for this track/year/exam type.
        Setting::updateOrCreate(['key' => 'promotion_thresholds'], ['value' => ['AP' => [$yl => ['exam' => 75]]]]);

        $this->actingAs($u, 'sanctum')->postJson("/api/quizzes/{$quizId}/results", [
            'resident_id' => $resident->id, 'score' => 80, 'taken_at' => now()->toDateString(),
        ])->assertStatus(201);

        $this->assertContains($resident->fresh()->promotion_status, ['eligible', 'ineligible']);
    }

    /** Slice 3 (flowchart K): RISE is an accepted exam type. */
    public function test_rise_exam_type_accepted(): void
    {
        $u = $this->officerWithInstitution();
        $quiz = $this->actingAs($u, 'sanctum')->postJson('/api/quizzes', [
            'title' => 'RISE', 'type' => 'rise', 'max_score' => 100,
        ]);
        $quiz->assertStatus(201)->assertJsonPath('type', 'rise');

        // An unknown type is rejected.
        $bad = $this->actingAs($u, 'sanctum')->postJson('/api/quizzes', [
            'title' => 'X', 'type' => 'midterm', 'max_score' => 100,
        ]);
        $bad->assertStatus(422);
    }

    public function test_transfer_requested(): void
    {
        $u = $this->officerWithInstitution();
        [, $resident] = $this->createResident($u);
        $dest = Institution::create(['name' => 'Dest ' . uniqid(), 'registration_status' => 'approved']);

        $this->actingAs($u, 'sanctum')
            ->postJson("/api/residents/{$resident->id}/transfers", ['to_institution_id' => $dest->id, 'reason' => 'move'])
            ->assertStatus(201);
    }

    /** Slice 2: resident portfolio endpoint aggregates the resident's training records. */
    public function test_resident_portfolio_aggregates_records(): void
    {
        $u = $this->officerWithInstitution();
        [, $resident] = $this->createResident($u);

        $this->actingAs($u, 'sanctum')->postJson('/api/case-logs', [
            'resident_id' => $resident->id, 'case_type' => 'Biopsy', 'procedure' => 'FFPE', 'count' => 12,
        ])->assertStatus(201);
        $this->actingAs($u, 'sanctum')->postJson('/api/research-papers', [
            'resident_id' => $resident->id, 'title' => 'Paper', 'stage' => 'published',
        ])->assertStatus(201);
        $this->actingAs($u, 'sanctum')->postJson('/api/consultant-evaluations', [
            'resident_id' => $resident->id, 'period' => '2026-Q1', 'recommendation' => 'continue',
        ])->assertStatus(201);

        $res = $this->actingAs($u, 'sanctum')->getJson("/api/residents/{$resident->id}");
        $res->assertStatus(200)
            ->assertJsonPath('resident.id', $resident->id)
            ->assertJsonCount(1, 'case_logs')
            ->assertJsonCount(1, 'research_papers')
            ->assertJsonCount(1, 'consultant_evaluations');
    }
}

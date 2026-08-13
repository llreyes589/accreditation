<?php

namespace Tests\Feature;

use App\Models\{
    Accreditation, AccreditationInspection, ChecklistItem, CorrectiveAction,
    CorrectiveActionStatusLog, Finding, Institution, Role, TrainingOfficer, User,
};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class FindingsFlowTest extends TestCase
{
    use RefreshDatabase;

    private function roles(): void
    {
        foreach (['TrainingInstitution', 'TrainingOfficer', 'Resident', 'Consultant', 'Admin', 'Accreditor'] as $rn) {
            Role::firstOrCreate(['name' => $rn]);
        }
    }

    private function officerWithInstitution(): User
    {
        $this->roles();
        $u = User::create([
            'name' => 'Officer', 'username' => 'off_' . uniqid(), 'email' => uniqid() . '@x.ph',
            'password' => bcrypt('password1'), 'status' => 'approved', 'email_verified_at' => now(),
        ]);
        $u->assignRole('TrainingOfficer');
        $i = Institution::create(['name' => 'Inst ' . uniqid(), 'registration_status' => 'approved']);
        TrainingOfficer::create(['user_id' => $u->id, 'institution_id' => $i->id]);
        return $u;
    }

    private function staff(): User
    {
        $this->roles();
        $u = User::create([
            'name' => 'Reviewer', 'username' => 'rev_' . uniqid(), 'email' => uniqid() . '@x.ph',
            'password' => bcrypt('password1'), 'status' => 'approved', 'email_verified_at' => now(),
        ]);
        $u->assignRole('Accreditor');
        return $u;
    }

    /** Build an inspection attached to a fresh accreditation the officer owns. */
    private function inspectionFor(User $officer): AccreditationInspection
    {
        $instId = $officer->trainingOfficer->institution_id;
        $acc = Accreditation::create([
            'institution_id' => $instId, 'status' => 'inspected', 'checklist_snapshot' => [],
        ]);
        return AccreditationInspection::create([
            'accreditation_id' => $acc->id, 'accreditor_id' => $this->staff()->id,
            'status' => 'submitted', 'answers' => [],
        ]);
    }

    public function test_full_corrective_action_workflow(): void
    {
        $officer = $this->officerWithInstitution();
        $reviewer = $this->staff();
        $inspection = $this->inspectionFor($officer);

        // Reviewer raises a finding.
        $finding = $this->actingAs($reviewer, 'sanctum')->postJson('/api/staff/findings', [
            'accreditation_inspection_id' => $inspection->id,
            'title' => 'Missing SOP', 'description' => 'No SOP for frozen section.', 'severity' => 'major',
        ]);
        $finding->assertStatus(201);
        $findingId = $finding->json('id');

        // Institution proposes a corrective action.
        $action = $this->actingAs($officer, 'sanctum')->postJson('/api/corrective-actions', [
            'finding_id' => $findingId, 'action_plan' => 'Draft and approve SOP.', 'due_date' => now()->addDays(14)->toDateString(),
        ]);
        $action->assertStatus(201);
        $actionId = $action->json('id');

        // Institution uploads evidence.
        $evidence = $this->actingAs($officer, 'sanctum')
            ->post("/api/corrective-actions/{$actionId}/evidence", ['file' => UploadedFile::fake()->create('sop.pdf', 12, 'application/pdf')]);
        $evidence->assertStatus(201);

        // Institution marks resolved.
        $this->actingAs($officer, 'sanctum')->postJson("/api/corrective-actions/{$actionId}/resolve")->assertStatus(200);

        // Reviewer verifies.
        $verify = $this->actingAs($reviewer, 'sanctum')
            ->postJson("/api/staff/corrective-actions/{$actionId}/verify", ['decision' => 'verified', 'comment' => 'SOP accepted.']);
        $verify->assertStatus(200)->assertJsonPath('status', 'verified');

        // History preserved: status log has the transitions.
        $this->assertDatabaseHas('corrective_action_status_logs', ['corrective_action_id' => $actionId, 'status' => 'verified']);
        $this->assertCount(3, CorrectiveActionStatusLog::where('corrective_action_id', $actionId)->get());
    }

    public function test_reject_requires_comment_and_reopens(): void
    {
        $officer = $this->officerWithInstitution();
        $reviewer = $this->staff();
        $inspection = $this->inspectionFor($officer);

        $findingId = $this->actingAs($reviewer, 'sanctum')
            ->postJson('/api/staff/findings', ['accreditation_inspection_id' => $inspection->id, 'title' => 'T', 'description' => 'D'])
            ->json('id');
        $actionId = $this->actingAs($officer, 'sanctum')
            ->postJson('/api/corrective-actions', ['finding_id' => $findingId, 'action_plan' => 'Plan'])
            ->json('id');

        // Reject without a comment -> 422.
        $this->actingAs($reviewer, 'sanctum')
            ->postJson("/api/staff/corrective-actions/{$actionId}/verify", ['decision' => 'rejected'])
            ->assertStatus(422);

        // Reject with a comment -> reopened + log records the comment.
        $reject = $this->actingAs($reviewer, 'sanctum')
            ->postJson("/api/staff/corrective-actions/{$actionId}/verify", ['decision' => 'rejected', 'comment' => 'Still inadequate.']);
        $reject->assertStatus(200)->assertJsonPath('status', 'reopened');
        $this->assertDatabaseHas('corrective_action_status_logs', ['corrective_action_id' => $actionId, 'status' => 'reopened', 'comment' => 'Still inadequate.']);
    }

    public function test_cross_institution_action_is_forbidden(): void
    {
        $officer = $this->officerWithInstitution();
        $other = $this->officerWithInstitution(); // different institution
        $inspection = $this->inspectionFor($other);

        $findingId = $this->actingAs($this->staff(), 'sanctum')
            ->postJson('/api/staff/findings', ['accreditation_inspection_id' => $inspection->id, 'title' => 'T', 'description' => 'D'])
            ->json('id');

        $this->actingAs($officer, 'sanctum')
            ->postJson('/api/corrective-actions', ['finding_id' => $findingId, 'action_plan' => 'Plan'])
            ->assertStatus(403);
    }

    public function test_reviewer_cannot_create_corrective_action(): void
    {
        $reviewer = $this->staff();
        $inspection = $this->inspectionFor($this->officerWithInstitution());
        $findingId = $this->actingAs($reviewer, 'sanctum')
            ->postJson('/api/staff/findings', ['accreditation_inspection_id' => $inspection->id, 'title' => 'T', 'description' => 'D'])
            ->json('id');

        $this->actingAs($reviewer, 'sanctum')
            ->postJson('/api/corrective-actions', ['finding_id' => $findingId, 'action_plan' => 'Plan'])
            ->assertStatus(403);
    }
}

<?php

namespace Tests\Feature;

use App\Models\{User, Role, Institution, Accreditation, AccreditationDecision, Setting};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccreditationDecisionTest extends TestCase
{
    use RefreshDatabase;

    private function roles(): void
    {
        foreach (['Admin', 'Accreditor', 'TrainingOfficer', 'TrainingInstitution'] as $r) {
            Role::firstOrCreate(['name' => $r]);
        }
    }

    private function makeAccreditation($status = Accreditation::STATUS_INSPECTED)
    {
        $inst = Institution::create(['name' => 'I' . uniqid(), 'registration_status' => 'approved']);
        return Accreditation::create(['institution_id' => $inst->id, 'status' => $status, 'checklist_snapshot' => []]);
    }

    private function token($role)
    {
        $u = User::create([
            'name' => $role, 'username' => $role . uniqid(), 'email' => uniqid() . '@x.ph',
            'password' => bcrypt('password1'), 'status' => 'approved', 'email_verified_at' => now(),
        ]);
        $u->assignRole($role);
        return $u->createToken('t')->plainTextToken;
    }

    public function test_accreditor_can_record_draft_recommendation()
    {
        $this->roles();
        $acc = $this->makeAccreditation();
        $tR = $this->token('Accreditor');

        $res = $this->withToken($tR)->postJson("/api/accreditor/accreditations/{$acc->id}/decision-draft", [
            'outcome' => 'draft', 'notes' => 'Recommend probationary.',
        ]);

        $res->assertStatus(201);
        $this->assertDatabaseHas('accreditation_decisions', [
            'accreditation_id' => $acc->id, 'outcome' => 'draft',
        ]);
        // Draft does NOT change the accreditation status.
        $this->assertDatabaseHas('accreditations', ['id' => $acc->id, 'status' => Accreditation::STATUS_INSPECTED]);
    }

    public function test_final_approved_records_ledger_row_and_period()
    {
        $this->roles();
        Setting::updateOrCreate(['key' => 'accreditation_years'], ['value' => 3]);
        $acc = $this->makeAccreditation();
        $tA = $this->token('Admin');

        $res = $this->withToken($tA)->postJson("/api/staff/accreditations/{$acc->id}/decision", [
            'outcome' => 'approved',
        ]);

        $res->assertStatus(200)->assertJsonPath('status', 'approved');
        $this->assertNotNull($res->json('valid_until'));
        $this->assertDatabaseHas('accreditation_decisions', [
            'accreditation_id' => $acc->id, 'outcome' => 'approved',
        ]);
    }

    public function test_final_probationary_sets_probationary_status()
    {
        $this->roles();
        $acc = $this->makeAccreditation();
        $tA = $this->token('Admin');

        $res = $this->withToken($tA)->postJson("/api/staff/accreditations/{$acc->id}/decision", [
            'outcome' => 'probationary',
        ]);

        $res->assertStatus(200)->assertJsonPath('status', 'probationary');
        $this->assertDatabaseHas('accreditations', ['id' => $acc->id, 'status' => Accreditation::STATUS_PROBATIONARY]);
    }

    public function test_final_rejected_records_ledger_row()
    {
        $this->roles();
        $acc = $this->makeAccreditation();
        $tA = $this->token('Admin');

        $res = $this->withToken($tA)->postJson("/api/staff/accreditations/{$acc->id}/decision", [
            'outcome' => 'rejected',
        ]);

        $res->assertStatus(200)->assertJsonPath('status', 'rejected');
        $this->assertDatabaseHas('accreditation_decisions', [
            'accreditation_id' => $acc->id, 'outcome' => 'rejected',
        ]);
    }

    public function test_decisions_endpoint_returns_history()
    {
        $this->roles();
        $acc = $this->makeAccreditation();
        AccreditationDecision::create([
            'accreditation_id' => $acc->id, 'outcome' => 'draft', 'decided_by' => 1,
        ]);
        AccreditationDecision::create([
            'accreditation_id' => $acc->id, 'outcome' => 'approved', 'decided_by' => 1,
        ]);
        $tA = $this->token('Admin');

        $res = $this->withToken($tA)->getJson("/api/staff/accreditations/{$acc->id}/decisions");

        $res->assertStatus(200);
        $this->assertCount(2, $res->json('decisions'));
    }

    public function test_unauthorized_role_cannot_record_decision()
    {
        $this->roles();
        $acc = $this->makeAccreditation();
        // TrainingOfficer is not in the staff group (Admin|Accreditor).
        $tO = $this->token('TrainingOfficer');

        $res = $this->withToken($tO)->postJson("/api/staff/accreditations/{$acc->id}/decision", [
            'outcome' => 'approved',
        ]);

        $res->assertStatus(403);
    }

    public function test_decision_draft_requires_inspected_state()
    {
        $this->roles();
        $acc = $this->makeAccreditation(Accreditation::STATUS_PENDING);
        $tR = $this->token('Accreditor');

        $res = $this->withToken($tR)->postJson("/api/accreditor/accreditations/{$acc->id}/decision-draft", [
            'outcome' => 'draft',
        ]);

        $res->assertStatus(422);
    }

    public function test_decision_is_append_only_history_preserved()
    {
        $this->roles();
        Setting::updateOrCreate(['key' => 'accreditation_years'], ['value' => 1]);
        $acc = $this->makeAccreditation();
        $tR = $this->token('Accreditor');
        $tA = $this->token('Admin');

        $this->withToken($tR)->postJson("/api/accreditor/accreditations/{$acc->id}/decision-draft", [
            'outcome' => 'draft', 'notes' => 'first',
        ]);
        $this->withToken($tA)->postJson("/api/staff/accreditations/{$acc->id}/decision", [
            'outcome' => 'approved',
        ]);

        // Both the draft and the final decision persist (history preserved).
        $this->assertDatabaseCount('accreditation_decisions', 2);
    }
}

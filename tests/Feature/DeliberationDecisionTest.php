<?php

namespace Tests\Feature;

use App\Models\{Accreditation, AccreditationDecision, Institution, Role, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliberationDecisionTest extends TestCase
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
        Role::firstOrCreate(['name' => 'TrainingInstitution']);
        $inst = Institution::create(['name' => 'Inst ' . uniqid(), 'registration_status' => 'approved']);
        return $inst->accreditations()->create([
            'checklist_snapshot' => [],
            'status' => Accreditation::STATUS_INSPECTED,
            'submission_type' => 'new',
        ]);
    }

    /** t_44c4b896: recordDecision captures recommendation + vote count + notes. */
    public function test_record_decision_stores_recommendation_and_vote_count(): void
    {
        $admin = $this->admin();
        $acc = $this->inspectedAccreditation();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/staff/accreditations/{$acc->id}/decision", [
                'outcome' => 'approved',
                'recommendation' => AccreditationDecision::RECOMMENDATION_3_YEARS,
                'vote_count' => 5,
                'notes' => 'Unanimous for 3-year accreditation.',
            ])
            ->assertStatus(200)
            ->assertJsonPath('status', 'approved');

        $decision = $acc->decisions()->latest()->first();
        $this->assertSame(AccreditationDecision::RECOMMENDATION_3_YEARS, $decision->recommendation);
        $this->assertSame(5, $decision->vote_count);
        $this->assertSame('Unanimous for 3-year accreditation.', $decision->notes);
    }

    /** t_44c4b896: decision list returns the recommendation + vote count. */
    public function test_decision_list_includes_recommendation_and_vote_count(): void
    {
        $admin = $this->admin();
        $acc = $this->inspectedAccreditation();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/staff/accreditations/{$acc->id}/decision", [
                'outcome' => 'probationary',
                'recommendation' => AccreditationDecision::RECOMMENDATION_3_YEARS_CONDITIONAL,
                'vote_count' => 3,
            ])
            ->assertStatus(200);

        $list = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/staff/accreditations/{$acc->id}/decisions")
            ->assertStatus(200)
            ->json('decisions');

        $latest = end($list);
        $this->assertSame(AccreditationDecision::RECOMMENDATION_3_YEARS_CONDITIONAL, $latest['recommendation']);
        $this->assertSame(3, $latest['vote_count']);
    }

    /** t_44c4b896: accreditor draft may carry a recommendation + vote count. */
    public function test_accreditor_draft_stores_recommendation_and_vote_count(): void
    {
        Role::firstOrCreate(['name' => 'Accreditor']);
        $accreditor = User::create(['name' => 'Acc', 'username' => 'acc_' . uniqid(), 'email' => uniqid() . '@x.ph', 'password' => bcrypt('password1'), 'status' => 'approved', 'email_verified_at' => now()]);
        $accreditor->assignRole('Accreditor');
        $acc = $this->inspectedAccreditation();

        $this->actingAs($accreditor, 'sanctum')
            ->postJson("/api/accreditor/accreditations/{$acc->id}/decision-draft", [
                'outcome' => 'draft',
                'recommendation' => AccreditationDecision::RECOMMENDATION_1_YEAR,
                'vote_count' => 4,
                'notes' => 'Lean towards 1 year.',
            ])
            ->assertStatus(201);

        $decision = $acc->decisions()->latest()->first();
        $this->assertSame(AccreditationDecision::RECOMMENDATION_1_YEAR, $decision->recommendation);
        $this->assertSame(4, $decision->vote_count);
    }

    /** t_0171beef + t_f18a9c4a: final status records track SET (AP,CP) + validity period. */
    public function test_record_decision_stores_track_and_validity(): void
    {
        $admin = $this->admin();
        $acc = $this->inspectedAccreditation();

        $validUntil = today()->addYears(3)->toDateString();
        $resp = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/staff/accreditations/{$acc->id}/decision", [
                'outcome' => 'approved',
                'track' => ['AP', 'CP'],
                'valid_until' => $validUntil,
            ])
            ->assertStatus(200);

        $acc->refresh();
        $this->assertSame('AP,CP', $acc->track);
        $this->assertSame(['AP', 'CP'], $acc->accreditedTracks());
        $this->assertSame('approved', $acc->status);
        $this->assertNotNull($acc->valid_from);
        $this->assertSame($validUntil, $acc->valid_until->toDateString());

        $this->assertSame('AP,CP', $resp->json('track'));
    }

    /** t_f18a9c4a: a single-track accreditation stores as a single-element set. */
    public function test_record_decision_single_track(): void
    {
        $admin = $this->admin();
        $acc = $this->inspectedAccreditation();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/staff/accreditations/{$acc->id}/decision", [
                'outcome' => 'approved',
                'track' => ['CP'],
            ])
            ->assertStatus(200);

        $acc->refresh();
        $this->assertSame('CP', $acc->track);
        $this->assertSame(['CP'], $acc->accreditedTracks());
    }
}

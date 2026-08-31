<?php

namespace Tests\Feature;

use App\Models\{Accreditation, AccreditationDecision, Institution, Role, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bug fix: a decision's validity period must follow the chosen recommendation
 * (3-year incl. conditional -> 3 years; 1-year -> 1 year), not a global Setting.
 */
class DecisionValidityTest extends TestCase
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
        return $inst->accreditations()->create(['checklist_snapshot' => [], 'status' => Accreditation::STATUS_INSPECTED, 'submission_type' => 'new']);
    }

    private function decide(Accreditation $acc, string $recommendation): Accreditation
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/staff/accreditations/{$acc->id}/decision", [
                'outcome' => 'approved',
                'recommendation' => $recommendation,
            ])
            ->assertStatus(200);
        return $acc->fresh();
    }

    public function test_3_year_recommendation_grants_3_year_validity(): void
    {
        $acc = $this->inspectedAccreditation();
        $acc = $this->decide($acc, AccreditationDecision::RECOMMENDATION_3_YEARS);
        $this->assertEquals(3, today()->diffInYears($acc->valid_until));
    }

    public function test_3_year_conditional_recommendation_grants_3_year_validity(): void
    {
        $acc = $this->inspectedAccreditation();
        $acc = $this->decide($acc, AccreditationDecision::RECOMMENDATION_3_YEARS_CONDITIONAL);
        $this->assertEquals(3, today()->diffInYears($acc->valid_until));
    }

    public function test_1_year_recommendation_grants_1_year_validity(): void
    {
        $acc = $this->inspectedAccreditation();
        $acc = $this->decide($acc, AccreditationDecision::RECOMMENDATION_1_YEAR);
        $this->assertEquals(1, today()->diffInYears($acc->valid_until));
    }

    public function test_explicit_valid_until_still_wins_over_recommendation(): void
    {
        $acc = $this->inspectedAccreditation();
        $until = today()->addYears(2)->toDateString();
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/staff/accreditations/{$acc->id}/decision", [
                'outcome' => 'approved',
                'recommendation' => AccreditationDecision::RECOMMENDATION_3_YEARS,
                'valid_until' => $until,
            ])
            ->assertStatus(200);
        $this->assertEquals($until, $acc->fresh()->valid_until->toDateString());
    }

    public function test_rejected_decision_has_no_validity(): void
    {
        $acc = $this->inspectedAccreditation();
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/staff/accreditations/{$acc->id}/decision", [
                'outcome' => 'rejected',
                'recommendation' => AccreditationDecision::RECOMMENDATION_1_YEAR,
            ])
            ->assertStatus(200);
        $this->assertNull($acc->fresh()->valid_until);
    }
}

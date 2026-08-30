<?php

namespace Tests\Feature;

use App\Models\{Accreditation, Institution, Resident, Role, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResidentTrackPropagationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Role::firstOrCreate(['name' => 'Admin']);
        $u = User::create(['name' => 'Admin', 'username' => 'adm_' . uniqid(), 'email' => uniqid() . '@x.ph', 'password' => bcrypt('password1'), 'status' => 'approved', 'email_verified_at' => now()]);
        $u->assignRole('Admin');
        return $u;
    }

    private function approvedInstitutionWithAccreditation(string $trackSet): Institution
    {
        Role::firstOrCreate(['name' => 'TrainingInstitution']);
        $owner = User::create(['name' => 'Owner', 'username' => 'own_' . uniqid(), 'email' => uniqid() . '@x.ph', 'password' => bcrypt('password1'), 'status' => 'approved', 'email_verified_at' => now()]);
        $owner->assignRole('TrainingInstitution');
        $inst = Institution::create(['name' => 'Inst ' . uniqid(), 'registration_status' => 'approved', 'user_id' => $owner->id]);
        $acc = $inst->accreditations()->create([
            'checklist_snapshot' => [],
            'status' => Accreditation::STATUS_INSPECTED,
            'submission_type' => 'new',
        ]);
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/staff/accreditations/{$acc->id}/decision", [
                'outcome' => 'approved',
                'track' => explode(',', $trackSet),
            ])
            ->assertStatus(200);
        return $inst->fresh();
    }

    private function registerResident(Institution $inst, string $track): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/register/resident', [
            'institution_id' => $inst->id,
            'name' => 'Res ' . uniqid(),
            'username' => 'res_' . uniqid(),
            'email' => uniqid() . '@x.ph',
            'password' => 'password1',
            'password_confirmation' => 'password1',
            'track' => $track,
        ]);
    }

    /** t_f18a9c4a: resident may register with a track within the institution's accredited set. */
    public function test_resident_allowed_within_accredited_track(): void
    {
        $inst = $this->approvedInstitutionWithAccreditation('AP,CP');

        $this->registerResident($inst, 'CP')->assertStatus(201);
        $this->assertDatabaseHas('residents', ['institution_id' => $inst->id, 'track' => 'CP']);
    }

    /** t_f18a9c4a: AP_CP (both) is allowed when the institution is accredited for AP and CP. */
    public function test_resident_allowed_apcp_when_both_accredited(): void
    {
        $inst = $this->approvedInstitutionWithAccreditation('AP,CP');

        $this->registerResident($inst, 'AP_CP')->assertStatus(201);
    }

    /** t_f18a9c4a: resident registration is rejected for a track outside the institution's set. */
    public function test_resident_rejected_outside_accredited_track(): void
    {
        // Institution accredited for CP only.
        $inst = $this->approvedInstitutionWithAccreditation('CP');

        $this->registerResident($inst, 'AP')
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'This institution is not accredited for the selected track. Accredited tracks: CP.']);
    }

    /** t_f18a9c4a: when the institution has no accreditation yet, any valid track is permitted. */
    public function test_resident_allowed_when_no_accreditation(): void
    {
        Role::firstOrCreate(['name' => 'TrainingInstitution']);
        $owner = User::create(['name' => 'Owner', 'username' => 'own_' . uniqid(), 'email' => uniqid() . '@x.ph', 'password' => bcrypt('password1'), 'status' => 'approved', 'email_verified_at' => now()]);
        $owner->assignRole('TrainingInstitution');
        $inst = Institution::create(['name' => 'Inst ' . uniqid(), 'registration_status' => 'approved', 'user_id' => $owner->id]);

        $this->registerResident($inst, 'AP')->assertStatus(201);
    }
}

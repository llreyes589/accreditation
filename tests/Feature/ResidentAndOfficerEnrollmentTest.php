<?php

namespace Tests\Feature;

use App\Models\{Institution, Resident, Role, User, TrainingOfficer};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * t_bbe1880b — Verify/fix resident self-registration + training-officer enrollment flow.
 * Exercises both flows end-to-end and asserts the post-conditions the PSP residency
 * restoration depends on (Resident.user_id + institution_id, TrainingOfficer linkage).
 */
class ResidentAndOfficerEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    private function institutionOwner(): User
    {
        foreach (['TrainingInstitution', 'TrainingOfficer', 'Resident'] as $rn) {
            Role::firstOrCreate(['name' => $rn]);
        }
        $u = User::create([
            'name' => 'Owner', 'username' => 'own_' . uniqid(), 'email' => uniqid() . '@x.ph',
            'password' => bcrypt('password1'), 'status' => 'approved', 'email_verified_at' => now(),
        ]);
        $u->assignRole('TrainingInstitution');
        return $u;
    }

    private function approvedInstitution(User $owner): Institution
    {
        return Institution::create(['name' => 'Inst ' . uniqid(), 'registration_status' => 'approved', 'user_id' => $owner->id]);
    }

    /** Resident self-registration (public) creates the user + resident linked to the institution. */
    public function test_resident_self_registration_links_to_institution(): void
    {
        Notification::fake();
        $owner = $this->institutionOwner();
        $inst = $this->approvedInstitution($owner);

        $res = $this->postJson('/api/register/resident', [
            'institution_id' => $inst->id,
            'name' => 'Resident A',
            'username' => 'res_' . uniqid(),
            'email' => uniqid() . '@x.ph',
            'password' => 'password1',
            'password_confirmation' => 'password1',
            'track' => 'AP',
        ])->assertStatus(201);

        $userId = $res->json('user.id');
        $this->assertNotNull($userId);
        $resident = Resident::where('user_id', $userId)->first();
        $this->assertNotNull($resident);
        $this->assertSame($inst->id, $resident->institution_id);
        $this->assertSame('AP', $resident->track);
        // Dev mode auto-approves the account so the resident can sign in immediately.
        $this->assertSame('approved', User::find($userId)->status);
    }

    /** Self-registration is blocked for an unapproved institution. */
    public function test_resident_self_registration_blocked_for_unapproved_institution(): void
    {
        $owner = $this->institutionOwner();
        $inst = Institution::create(['name' => 'Inst ' . uniqid(), 'registration_status' => 'pending', 'user_id' => $owner->id]);

        $this->postJson('/api/register/resident', [
            'institution_id' => $inst->id,
            'name' => 'Resident B',
            'username' => 'res_' . uniqid(),
            'email' => uniqid() . '@x.ph',
            'password' => 'password1',
            'password_confirmation' => 'password1',
            'track' => 'CP',
        ])->assertStatus(422);
    }

    /** Institution admin enrolls a training officer; the officer is linked to the institution. */
    public function test_training_officer_enrollment_links_to_institution(): void
    {
        Notification::fake();
        $owner = $this->institutionOwner();
        $inst = $this->approvedInstitution($owner);

        $res = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/training-officers', [
                'name' => 'Officer C',
                'username' => 'to_' . uniqid(),
                'email' => uniqid() . '@x.ph',
                'password' => 'password1',
                'phone' => '0917',
                'telegram_handle' => '@to',
            ])
            ->assertStatus(201);

        $userId = $res->json('id');
        $this->assertNotNull($userId);
        $this->assertTrue(User::find($userId)->hasRole('TrainingOfficer'));
        $officer = TrainingOfficer::where('user_id', $userId)->first();
        $this->assertNotNull($officer);
        $this->assertSame($inst->id, $officer->institution_id);
        $this->assertSame('0917', $officer->phone);
        $this->assertSame('@to', $officer->telegram_handle);
    }

    /** Officer enrollment is scoped to the authenticated owner's institution, not a foreign one. */
    public function test_training_officer_enrollment_scoped_to_owners_institution(): void
    {
        $owner = $this->institutionOwner();
        $inst = $this->approvedInstitution($owner);
        $other = $this->approvedInstitution($this->institutionOwner());

        $res = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/training-officers', [
                'name' => 'Officer D',
                'username' => 'to_' . uniqid(),
                'email' => uniqid() . '@x.ph',
                'password' => 'password1',
            ])
            ->assertStatus(201);

        $userId = $res->json('id');
        $officer = TrainingOfficer::where('user_id', $userId)->first();
        $this->assertSame($inst->id, $officer->institution_id);
        $this->assertNotSame($other->id, $officer->institution_id);
    }
}

<?php

namespace Tests\Feature;

use App\Models\{Institution, Resident, Role, User, ResidentTransfer, TrainingOfficer};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * t_b0a9f0de — Terminology cleanup: transfer disposition uses accepted/denied/pending
 * (distinct from an accreditation decision's approved/probationary/rejected).
 */
class TransferDispositionTest extends TestCase
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

    public function test_transfer_defaults_to_pending(): void
    {
        $officer = $this->officerWithInstitution();
        $resident = $this->makeResident($officer);
        $dest = Institution::create(['name' => 'Dest ' . uniqid(), 'registration_status' => 'approved']);

        $this->actingAs($officer, 'sanctum')
            ->postJson("/api/residents/{$resident->id}/transfers", ['to_institution_id' => $dest->id, 'reason' => 'move'])
            ->assertStatus(201);

        $this->assertSame(ResidentTransfer::STATUS_PENDING, ResidentTransfer::latest()->first()->status);
    }

    public function test_reject_transfer_records_denied_not_rejected(): void
    {
        $officer = $this->officerWithInstitution();
        $resident = $this->makeResident($officer);
        $dest = Institution::create(['name' => 'Dest ' . uniqid(), 'registration_status' => 'approved']);
        $destOwner = User::create(['name' => 'DestOwner', 'username' => 'do_' . uniqid(), 'email' => uniqid() . '@x.ph', 'password' => bcrypt('password1'), 'status' => 'approved', 'email_verified_at' => now()]);
        $destOwner->assignRole('TrainingOfficer');
        TrainingOfficer::create(['user_id' => $destOwner->id, 'institution_id' => $dest->id]);

        $trf = $this->actingAs($officer, 'sanctum')
            ->postJson("/api/residents/{$resident->id}/transfers", ['to_institution_id' => $dest->id, 'reason' => 'move'])
            ->json('id');

        $this->actingAs($destOwner, 'sanctum')
            ->postJson("/api/transfers/{$trf}/reject")
            ->assertStatus(200);

        $transfer = ResidentTransfer::find($trf);
        $this->assertSame(ResidentTransfer::STATUS_DENIED, $transfer->status);
        $this->assertNotSame('rejected', $transfer->status);
    }

    public function test_accept_transfer_records_accepted_and_moves_resident(): void
    {
        $officer = $this->officerWithInstitution();
        $resident = $this->makeResident($officer);
        $dest = Institution::create(['name' => 'Dest ' . uniqid(), 'registration_status' => 'approved']);
        $destOwner = User::create(['name' => 'DestOwner', 'username' => 'do2_' . uniqid(), 'email' => uniqid() . '@x.ph', 'password' => bcrypt('password1'), 'status' => 'approved', 'email_verified_at' => now()]);
        $destOwner->assignRole('TrainingOfficer');
        TrainingOfficer::create(['user_id' => $destOwner->id, 'institution_id' => $dest->id]);

        $trf = $this->actingAs($officer, 'sanctum')
            ->postJson("/api/residents/{$resident->id}/transfers", ['to_institution_id' => $dest->id, 'reason' => 'move'])
            ->json('id');

        $this->actingAs($destOwner, 'sanctum')
            ->postJson("/api/transfers/{$trf}/accept")
            ->assertStatus(200);

        $this->assertSame(ResidentTransfer::STATUS_ACCEPTED, ResidentTransfer::find($trf)->status);
        $this->assertSame($dest->id, $resident->fresh()->institution_id);
    }
}

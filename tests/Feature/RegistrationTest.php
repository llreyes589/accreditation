<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_institution_creates_training_institution_owner(): void
    {
        Role::firstOrCreate(['name' => 'TrainingInstitution']);
        $payload = [
            'institution' => ['name' => 'St. Luke', 'address' => 'QC', 'hospital_level' => 'L3'],
            'name' => 'Dr. Owner',
            'username' => 'owner1',
            'email' => 'owner@stluke.ph',
            'password' => 'password1',
            'password_confirmation' => 'password1',
        ];
        $res = $this->postJson('/api/register/institution', $payload);
        $res->assertStatus(201);
        $user = User::where('username', 'owner1')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole('TrainingInstitution'));
        $this->assertFalse($user->hasRole('TrainingOfficer'));
        $institution = Institution::where('name', 'St. Luke')->first();
        $this->assertNotNull($institution);
        $this->assertEquals($user->id, $institution->user_id);
        $this->assertNull($user->trainingOfficer);
    }

    public function test_register_institution_stores_profile_fields(): void
    {
        Role::firstOrCreate(['name' => 'TrainingInstitution']);
        $payload = [
            'institution' => [
                'name' => 'St. Luke',
                'address' => '123 QC Ave',
                'hospital_level' => 'Level 3',
                'laboratory_level' => 'Lab 2',
                'bsf_category' => 'A',
                'director' => 'Dr. Director',
                'chairman' => 'Dr. Chairman',
                'contact_number' => '0281234567',
                'email' => 'inst@stluke.ph',
                'year_program_opened' => 2019,
                'region' => 'NCR',
                'province' => 'Metro Manila',
                'city' => 'Quezon City',
                'latitude' => 14.676,
                'longitude' => 121.043,
                ],
            'name' => 'Dr. Owner',
            'username' => 'owner9',
            'email' => 'owner9@stluke.ph',
            'password' => 'password1',
            'password_confirmation' => 'password1',
        ];
        $this->postJson('/api/register/institution', $payload)->assertStatus(201);
        $i = Institution::where('name', 'St. Luke')->first();
        $this->assertEquals('123 QC Ave', $i->address);
        $this->assertEquals('Level 3', $i->hospital_level);
        $this->assertEquals('Lab 2', $i->laboratory_level);
        $this->assertEquals('A', $i->bsf_category);
        $this->assertEquals('Dr. Director', $i->director);
        $this->assertEquals('Dr. Chairman', $i->chairman);
        $this->assertEquals('0281234567', $i->contact_number);
        $this->assertEquals('inst@stluke.ph', $i->email);
        $this->assertEquals(2019, $i->year_program_opened);
        $this->assertEquals('NCR', $i->region);
        $this->assertEquals('Metro Manila', $i->province);
        $this->assertEquals('Quezon City', $i->city);
        $this->assertEquals(14.676, $i->latitude);
        $this->assertEquals(121.043, $i->longitude);
    }

    public function test_training_institution_role_resolves_owned_institution(): void
    {
        Role::firstOrCreate(['name' => 'TrainingInstitution']);
        $u = User::create([
            'name' => 'Owner', 'username' => 'o2', 'email' => 'o2@x.ph',
            'password' => bcrypt('password1'), 'status' => 'approved', 'email_verified_at' => now(),
        ]);
        $u->assignRole('TrainingInstitution');
        $i = Institution::create(['name' => 'Makati Med', 'registration_status' => 'approved', 'user_id' => $u->id]);
        $this->actingAs($u, 'sanctum');
        $res = $this->getJson('/api/dashboard');
        $res->assertStatus(200)->assertJsonPath('institution.id', $i->id);
    }

    public function test_approve_training_institution_owner_approves_institution(): void
    {
        Role::firstOrCreate(['name' => 'TrainingInstitution']);
        $admin = User::create([
            'name' => 'A', 'username' => 'admin1', 'email' => 'admin1@x.ph',
            'password' => bcrypt('password1'), 'status' => 'approved', 'email_verified_at' => now(),
        ]);
        $admin->assignRole('Admin');
        $u = User::create([
            'name' => 'Owner', 'username' => 'o3', 'email' => 'o3@x.ph',
            'password' => bcrypt('password1'), 'status' => 'pending',
        ]);
        $u->assignRole('TrainingInstitution');
        $i = Institution::create(['name' => 'Asian Hosp', 'registration_status' => 'pending', 'user_id' => $u->id]);
        $this->actingAs($admin, 'sanctum');
        $this->postJson("/api/admin/users/{$u->id}/approve")->assertStatus(200);
        $this->assertEquals('approved', $u->fresh()->status);
        $this->assertEquals('approved', $i->fresh()->registration_status);
    }

    public function test_training_institution_can_access_institution_routes(): void
    {
        Role::firstOrCreate(['name' => 'TrainingInstitution']);
        $u = User::create([
            'name' => 'Owner', 'username' => 'o4', 'email' => 'o4@x.ph',
            'password' => bcrypt('password1'), 'status' => 'approved', 'email_verified_at' => now(),
        ]);
        $u->assignRole('TrainingInstitution');
        Institution::create(['name' => 'Doc Hosp', 'registration_status' => 'approved', 'user_id' => $u->id]);
        $this->actingAs($u, 'sanctum');
        $this->getJson('/api/documents')->assertStatus(200);
    }

    public function test_owner_can_read_and_update_institution_profile(): void
    {
        Role::firstOrCreate(['name' => 'TrainingInstitution']);
        $u = User::create([
            'name' => 'Owner', 'username' => 'o5', 'email' => 'o5@x.ph',
            'password' => bcrypt('password1'), 'status' => 'approved', 'email_verified_at' => now(),
        ]);
        $u->assignRole('TrainingInstitution');
        Institution::create([
            'name' => 'Read Inst', 'registration_status' => 'approved', 'user_id' => $u->id,
            'address' => 'Old Addr', 'laboratory_level' => 'Old Lab', 'bsf_category' => 'B',
            'director' => 'Old Dir', 'chairman' => 'Old Chair', 'contact_number' => '000',
            'email' => 'old@x.ph', 'year_program_opened' => 2000,
            'region' => 'Old Region', 'province' => 'Old Province', 'city' => 'Old City',
        ]);
        $this->actingAs($u, 'sanctum');

        $this->getJson('/api/institution-profile')
            ->assertStatus(200)
            ->assertJsonPath('name', 'Read Inst')
            ->assertJsonPath('year_program_opened', 2000);

        $this->putJson('/api/institution-profile', [
            'name' => 'Read Inst',
            'address' => 'New Addr',
            'hospital_level' => 'Level 1',
            'laboratory_level' => 'New Lab',
            'bsf_category' => 'C',
            'director' => 'New Dir',
            'chairman' => 'New Chair',
            'contact_number' => '09998887766',
            'email' => 'new@x.ph',
            'year_program_opened' => 2021,
            'region' => 'New Region',
            'province' => 'New Province',
            'city' => 'New City',
            'latitude' => 10.0,
            'longitude' => 124.0,
        ])->assertStatus(200)
            ->assertJsonPath('address', 'New Addr')
            ->assertJsonPath('laboratory_level', 'New Lab')
            ->assertJsonPath('bsf_category', 'C')
            ->assertJsonPath('director', 'New Dir')
            ->assertJsonPath('chairman', 'New Chair')
            ->assertJsonPath('contact_number', '09998887766')
            ->assertJsonPath('email', 'new@x.ph')
            ->assertJsonPath('year_program_opened', 2021)
            ->assertJsonPath('region', 'New Region')
            ->assertJsonPath('province', 'New Province')
            ->assertJsonPath('city', 'New City')
            ->assertJsonPath('latitude', 10)
            ->assertJsonPath('longitude', 124);
    }

    public function test_dev_registration_auto_approves_and_skips_email(): void
    {
        Role::firstOrCreate(['name' => 'TrainingInstitution']);
        // The test environment is non-production, so registration auto-approves and skips email.
        $this->assertFalse(app()->environment('production'));
        \Illuminate\Support\Facades\Notification::fake();
        $payload = [
            'institution' => ['name' => 'Dev Inst'],
            'name' => 'Dev Owner',
            'username' => 'devowner',
            'email' => 'devowner@x.ph',
            'password' => 'password1',
            'password_confirmation' => 'password1',
        ];
        $res = $this->postJson('/api/register/institution', $payload);
        $res->assertStatus(201)
            ->assertJsonPath('user.status', 'approved');
        $user = User::where('username', 'devowner')->first();
        $this->assertNotNull($user->email_verified_at);
        $this->assertEquals('approved', $user->fresh()->status);
        $this->assertEquals('approved', Institution::where('name', 'Dev Inst')->first()->registration_status);
        // No verification email should be sent in dev mode.
        \Illuminate\Support\Facades\Notification::assertNothingSent(\Illuminate\Auth\Notifications\VerifyEmail::class);
    }

    public function test_register_resident_auto_approved_in_dev(): void
    {
        Role::firstOrCreate(['name' => 'TrainingInstitution']);
        $owner = User::create(['name' => 'O', 'username' => 'devro', 'email' => 'devro@x.ph', 'password' => bcrypt('password1'), 'status' => 'approved', 'email_verified_at' => now()]);
        $owner->assignRole('TrainingInstitution');
        $i = Institution::create(['name' => 'Dev Inst R', 'registration_status' => 'approved', 'user_id' => $owner->id]);
        \Illuminate\Support\Facades\Notification::fake();
        $payload = [
            'institution_id' => $i->id,
            'name' => 'Dev Resident',
            'username' => 'devres',
            'email' => 'devres@x.ph',
            'password' => 'password1',
            'password_confirmation' => 'password1',
            'track' => 'AP',
        ];
        $res = $this->postJson('/api/register/resident', $payload);
        $res->assertStatus(201)->assertJsonPath('user.status', 'approved');
        $user = User::where('username', 'devres')->first();
        $this->assertNotNull($user->email_verified_at);
        \Illuminate\Support\Facades\Notification::assertNothingSent(\Illuminate\Auth\Notifications\VerifyEmail::class);
    }

    public function test_production_registration_stays_pending_and_sends_email(): void
    {
        Role::firstOrCreate(['name' => 'TrainingInstitution']);
        $this->app->instance('env', 'production');
        $this->assertTrue(app()->environment('production'));
        \Illuminate\Support\Facades\Notification::fake();
        $payload = [
            'institution' => ['name' => 'Prod Inst'],
            'name' => 'Prod Owner',
            'username' => 'prodowner',
            'email' => 'prodowner@x.ph',
            'password' => 'password1',
            'password_confirmation' => 'password1',
        ];
        $res = $this->postJson('/api/register/institution', $payload);
        $res->assertStatus(201)
            ->assertJsonPath('user.status', 'pending');
        $user = User::where('username', 'prodowner')->first();
        $this->assertNull($user->email_verified_at);
        $this->assertEquals('pending', Institution::where('name', 'Prod Inst')->first()->registration_status);
        \Illuminate\Support\Facades\Notification::assertSentTo($user, \Illuminate\Auth\Notifications\VerifyEmail::class);
    }
}

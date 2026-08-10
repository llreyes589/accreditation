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
            ->assertJsonPath('city', 'New City');
    }
}

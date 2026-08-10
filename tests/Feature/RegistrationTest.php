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
}

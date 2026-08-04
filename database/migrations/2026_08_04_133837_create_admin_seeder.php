<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Hash;

class CreateAdminSeeder extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $roleName = 'Admin';
        $adminRole = Role::firstOrCreate(['name' => $roleName]);

        $adminUser = User::firstOrCreate(
            ['email' => 'admin@accreditation.com'],
            [
                'name' => 'Admin User',
                'password' => '$2y$10$oNNWw8VbqkvdY7s8emQmMu3FLd0ykn6ekwpFRLmdpm1Dth50QUMpC',
                'status' => 'approved',
                'email_verified_at' => now(),
            ]
        );

        $adminUser->assignRole($adminRole->name);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $adminUser = User::where('email', 'admin@example.com')->first();

        if ($adminUser) {
            $adminUser->roles()->detach();
            $adminUser->delete();
        }
    }
}

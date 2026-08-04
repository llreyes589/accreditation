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
        $roleName = 'admin';
        $adminRole = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'api']);

        $adminUser = User::firstOrCreate(
            ['email' => 'admin@accreditation'],
            [
                'name' => 'Admin User',
                'password' => Hash::make('password'),
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

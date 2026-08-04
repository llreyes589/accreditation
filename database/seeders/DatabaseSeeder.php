<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        \App\Models\Setting::updateOrCreate(['key' => 'track_durations'], ['value' => ['AP' => 3, 'CP' => 3, 'AP_CP' => 3]]);
        \App\Models\Setting::updateOrCreate(['key' => 'promotion_thresholds'], ['value' => []]);
        \App\Models\Setting::updateOrCreate(['key' => 'accreditation_years'], ['value' => 1]);
        foreach (['Admin', 'Accreditor', 'TrainingOfficer', 'Resident'] as $role) \App\Models\Role::firstOrCreate(['name' => $role]);
    }
}

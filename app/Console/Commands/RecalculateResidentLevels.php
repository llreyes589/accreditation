<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\{Resident, Setting};

class RecalculateResidentLevels extends Command
{
    protected $signature = 'residents:recalculate-levels';
    protected $description = 'Recalculate residents calendar-derived year levels';
    public function handle()
    {
        $durations = Setting::getValue('track_durations', ['AP' => 3, 'CP' => 3, 'AP_CP' => 3]);
        Resident::whereNotNull('date_accepted')->each(function ($resident) use ($durations) {
            $resident->refreshYearLevel($durations[$resident->track] ?? 3);
        });
        return 0;
    }
}

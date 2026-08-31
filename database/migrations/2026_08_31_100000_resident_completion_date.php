<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('residents', function (Blueprint $table) {
            // Expected completion date for the training program (flowchart node C).
            // Set by the training officer when the resident profile is created.
            $table->date('expected_completion_date')->nullable()->after('date_accepted');
        });
    }

    public function down(): void
    {
        Schema::table('residents', function (Blueprint $table) {
            $table->dropColumn('expected_completion_date');
        });
    }
};

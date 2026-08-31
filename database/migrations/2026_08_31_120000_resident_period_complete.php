<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('residents', function (Blueprint $table) {
            // Set when the training officer marks the resident's current training
            // period as complete (flowchart node P).
            $table->timestamp('period_completed_at')->nullable()->after('completion_reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('residents', function (Blueprint $table) {
            $table->dropColumn('period_completed_at');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accreditation_decisions', function (Blueprint $table) {
            // Committee recommendation captured during deliberation
            // (3_years | 3_years_conditional | 1_year), distinct from the final outcome.
            $table->string('recommendation')->nullable()->after('outcome');
            // Number of votes cast for the recommendation (committee headcount, not per-member ballots).
            $table->unsignedInteger('vote_count')->nullable()->after('recommendation');
        });
    }

    public function down(): void
    {
        Schema::table('accreditation_decisions', function (Blueprint $table) {
            $table->dropColumn(['recommendation', 'vote_count']);
        });
    }
};

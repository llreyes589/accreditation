<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('residents', function (Blueprint $table) {
            // Set when the training officer reviews the resident's program completion
            // (flowchart node S), prior to submitting the portfolio for institutional review.
            $table->timestamp('completion_reviewed_at')->nullable()->after('promotion_evaluated_at');
        });
    }

    public function down(): void
    {
        Schema::table('residents', function (Blueprint $table) {
            $table->dropColumn('completion_reviewed_at');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accreditations', function (Blueprint $table) {
            // Final accreditation track: AP (Anatomic Pathology), CP (Clinical
            // Pathology), or APCP (both). Captured when the decision is recorded.
            $table->string('track')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('accreditations', function (Blueprint $table) {
            $table->dropColumn('track');
        });
    }
};

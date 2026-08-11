<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accreditations', function (Blueprint $table) {
            $table->string('submission_type')->default('new'); // new | renew
            $table->date('inspection_scheduled_at')->nullable();
            $table->date('submitted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('accreditations', function (Blueprint $table) {
            $table->dropColumn(['submission_type', 'inspection_scheduled_at', 'submitted_at']);
        });
    }
};

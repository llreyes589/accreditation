<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            $table->string('laboratory_level')->nullable();
            $table->string('bsf_category')->nullable();
            $table->string('director')->nullable();
            $table->string('chairman')->nullable();
            $table->string('contact_number')->nullable();
            $table->string('email')->nullable();
            $table->integer('year_program_opened')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            $table->dropColumn([
                'laboratory_level',
                'bsf_category',
                'director',
                'chairman',
                'contact_number',
                'email',
                'year_program_opened',
            ]);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accreditation_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accreditation_id')->constrained('accreditations')->cascadeOnDelete();
            $table->string('outcome'); // draft | approved | probationary | rejected
            $table->text('notes')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accreditation_decisions');
    }
};

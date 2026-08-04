<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddCoreOperationsTables extends Migration
{
    public function up()
    {
        Schema::create('resident_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignId('to_institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('reason')->nullable();
            $table->timestamps();
        });
        Schema::create('consultant_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultant_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('file_path');
            $table->date('expires_at')->nullable();
            $table->timestamps();
        });
        Schema::create('rotation_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('consultant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('category');
            $table->date('starts_at');
            $table->date('ends_at');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('rotation_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rotation_block_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resident_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('assigned');
            $table->decimal('grade', 8, 2)->nullable();
            $table->timestamps();
            $table->unique(['rotation_block_id', 'resident_id']);
        });
    }
    public function down()
    {
        Schema::dropIfExists('rotation_assignments'); Schema::dropIfExists('rotation_blocks');
        Schema::dropIfExists('consultant_documents'); Schema::dropIfExists('resident_transfers');
    }
}

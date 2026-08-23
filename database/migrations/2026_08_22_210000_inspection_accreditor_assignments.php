<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assignment of one or more accreditors (lead + members) to a single
 * accreditation inspection. Replaces the implicit single-accreditor link that
 * previously lived only on the AccreditationInspection row at submit time.
 *
 * Per the operating rule an accreditor may be assigned to at most three
 * inspections per calendar day (across any institution); that guard is enforced
 * in InspectionAssignmentService, not here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accreditation_inspection_accreditors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('accreditation_inspection_id');
            $table->foreign('accreditation_inspection_id', 'aia_inspection_fk')
                ->references('id')->on('accreditation_inspections')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id', 'aia_user_fk')
                ->references('id')->on('users')->cascadeOnDelete();
            $table->string('role')->default('member'); // lead | member
            $table->string('status')->default('invited'); // invited | accepted | declined | removed
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->text('decline_reason')->nullable();
            $table->timestamps();

            $table->unique(['accreditation_inspection_id', 'user_id'], 'aia_unique_accreditor');
            $table->index(['user_id', 'status'], 'aia_user_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accreditation_inspection_accreditors');
    }
};

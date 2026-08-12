<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Resident-management flowchart — remaining stages:
 *  - consultant_reviews     (G/H/I): consultant validates or returns a rotation for correction
 *  - consultant_evaluations (M):    periodic consultant evaluation of a resident
 *  - remediation_plans      (N/O):  remediation plan when requirements are not yet met
 *  - portfolio_archives     (U):    final-year portfolio archive
 * Every record is scoped to the owning institution through its resident (or the
 * rotation assignment's resident).
 */
return new class extends Migration
{
    public function up()
    {
        Schema::create('consultant_reviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('rotation_assignment_id')->constrained('rotation_assignments')->cascadeOnDelete();
            $t->foreignId('consultant_id')->nullable()->constrained('consultants')->nullOnDelete();
            $t->string('status');                                       // validated | returned
            $t->text('comments')->nullable();
            $t->timestamps();
            $t->index('status');
        });

        Schema::create('consultant_evaluations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('resident_id')->constrained('residents')->cascadeOnDelete();
            $t->foreignId('consultant_id')->nullable()->constrained('consultants')->nullOnDelete();
            $t->string('period');                                      // e.g. "2026 Q1", "Rotation 3"
            $t->json('ratings')->nullable();                            // { criterion: score }
            $t->text('comments')->nullable();
            $t->string('recommendation')->nullable();                  // continue | remediate
            $t->date('evaluated_at')->nullable();
            $t->timestamps();
        });

        Schema::create('remediation_plans', function (Blueprint $t) {
            $t->id();
            $t->foreignId('resident_id')->constrained('residents')->cascadeOnDelete();
            $t->text('reason');
            $t->text('plan');
            $t->string('status')->default('open');                     // open | in_progress | completed | closed
            $t->date('target_date')->nullable();
            $t->timestamps();
            $t->index('status');
        });

        Schema::create('portfolio_archives', function (Blueprint $t) {
            $t->id();
            $t->foreignId('resident_id')->constrained('residents')->cascadeOnDelete();
            $t->text('summary')->nullable();
            $t->string('status')->default('archived');                 // archived | sealed
            $t->date('archived_at')->nullable();
            $t->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('portfolio_archives');
        Schema::dropIfExists('remediation_plans');
        Schema::dropIfExists('consultant_evaluations');
        Schema::dropIfExists('consultant_reviews');
    }
};

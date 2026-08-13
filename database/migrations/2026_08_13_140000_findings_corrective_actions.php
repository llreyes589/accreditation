<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Findings & Corrective Actions module.
 *  - findings: raised from an accreditation inspection (optionally a non-compliant checklist item)
 *  - corrective_actions: the institution's plan to close a finding (many per finding)
 *  - corrective_action_evidence: files attached to an action
 *  - corrective_action_status_log: append-only history of status transitions (context preserved)
 * Both main tables use SoftDeletes; status changes are wrapped in transactions by the controller.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::create('findings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('accreditation_inspection_id')->constrained('accreditation_inspections')->cascadeOnDelete();
            $t->foreignId('checklist_item_id')->nullable()->constrained('checklist_items')->nullOnDelete();
            $t->string('title');
            $t->text('description');
            $t->string('severity')->default('major');                 // major | minor
            $t->string('status')->default('open');                    // open | in_progress | resolved | verified | rejected
            $t->foreignId('raised_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
            $t->index('status');
        });

        Schema::create('corrective_actions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('finding_id')->constrained('findings')->cascadeOnDelete();
            $t->text('action_plan');
            $t->date('due_date')->nullable();
            $t->string('status')->default('open');                    // open | in_progress | resolved | verified | reopened
            $t->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
            $t->index('status');
        });

        Schema::create('corrective_action_evidence', function (Blueprint $t) {
            $t->id();
            $t->foreignId('corrective_action_id')->constrained('corrective_actions')->cascadeOnDelete();
            $t->string('file_path');
            $t->string('original_name')->nullable();
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('corrective_action_status_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('corrective_action_id')->constrained('corrective_actions')->cascadeOnDelete();
            $t->string('status');
            $t->text('comment')->nullable();
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('logged_at')->useCurrent();
            $t->index('logged_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('corrective_action_status_logs');
        Schema::dropIfExists('corrective_action_evidence');
        Schema::dropIfExists('corrective_actions');
        Schema::dropIfExists('findings');
    }
};

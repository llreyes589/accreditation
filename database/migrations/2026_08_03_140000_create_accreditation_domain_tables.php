<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class CreateAccreditationDomainTables extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'status')) {
                $table->string('status')->default('pending');
            }
        });

        if (!Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->string('display_name')->nullable();
                $table->text('description')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('permissions')) {
            Schema::create('permissions', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->string('display_name')->nullable();
                $table->text('description')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('permission_role')) {
            Schema::create('permission_role', function (Blueprint $table) {
                $table->id();
                $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
                $table->foreignId('role_id')->constrained()->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['permission_id', 'role_id']);
            });
        }

        if (!Schema::hasTable('role_user')) {
            Schema::create('role_user', function (Blueprint $table) {
                $table->id();
                $table->foreignId('role_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['role_id', 'user_id']);
            });
        }

        if (!Schema::hasTable('institutions')) {
            Schema::create('institutions', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->text('address')->nullable();
                $table->string('hospital_level')->nullable();
                $table->string('registration_status')->default('pending');
                $table->softDeletes();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('institution_documents')) {
            Schema::create('institution_documents', function (Blueprint $table) {
                $table->id();
                $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
                $table->string('type');
                $table->string('file_path');
                $table->date('expires_at')->default(Carbon::create(now()->year + 1, 1, 1)->toDateString());
                $table->softDeletes();                
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('accreditations')) {
            Schema::create('accreditations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
                $table->json('checklist_snapshot')->nullable();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->date('valid_from')->nullable();
                $table->date('valid_until')->nullable();
                $table->string('status')->default('pending');
                $table->softDeletes();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('training_officers')) {
            Schema::create('training_officers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
                $table->string('phone')->nullable();
                $table->string('telegram_handle')->nullable();
                $table->softDeletes();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('residents')) {
            Schema::create('residents', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
                $table->string('track');
                $table->date('date_accepted')->nullable();
                $table->integer('age_at_enrollment')->nullable();
                $table->integer('year_level')->nullable();
                $table->softDeletes();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('consultants')) {
            Schema::create('consultants', function (Blueprint $table) {
                $table->id();
                $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->string('specialty');
                $table->text('credentials')->nullable();
                $table->json('linked_documents')->nullable();
                $table->softDeletes();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('quizzes')) {
            Schema::create('quizzes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
                $table->string('title');
                $table->string('type')->default('quiz');
                $table->integer('max_score')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('training_officers')->nullOnDelete();
                $table->softDeletes();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('quiz_results')) {
            Schema::create('quiz_results', function (Blueprint $table) {
                $table->id();
                $table->foreignId('quiz_id')->constrained()->cascadeOnDelete();
                $table->foreignId('resident_id')->constrained()->cascadeOnDelete();
                $table->decimal('score', 8, 2)->nullable();
                $table->timestamp('taken_at')->nullable();
                $table->softDeletes();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('case_logs')) {
            Schema::create('case_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('resident_id')->constrained()->cascadeOnDelete();
                $table->string('case_type');
                $table->string('procedure')->nullable();
                $table->integer('count')->default(1);
                $table->timestamp('logged_at')->nullable();
                $table->softDeletes();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('research_papers')) {
            Schema::create('research_papers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('resident_id')->constrained()->cascadeOnDelete();
                $table->string('title');
                $table->string('stage')->default('protocol_review');
                $table->text('notes')->nullable();
                $table->softDeletes();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('research_papers');
        Schema::dropIfExists('case_logs');
        Schema::dropIfExists('quiz_results');
        Schema::dropIfExists('quizzes');
        Schema::dropIfExists('consultants');
        Schema::dropIfExists('residents');
        Schema::dropIfExists('training_officers');
        Schema::dropIfExists('accreditations');
        Schema::dropIfExists('institution_documents');
        Schema::dropIfExists('institutions');
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('permission_role');

        if (!Schema::hasTable('roles')) {
            Schema::dropIfExists('permissions');
            Schema::dropIfExists('roles');
        }

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'status')) {
                $table->dropColumn('status');
            }
        });
    }
}

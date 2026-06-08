<?php

use App\Models\ContactQuestionnaireRun;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questionnaire_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 80)->unique();
            $table->string('name');
            $table->string('status', 20)->index();
            $table->unsignedBigInteger('published_version_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('questionnaire_template_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('questionnaire_template_id')->constrained('questionnaire_templates')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 20)->index();
            $table->jsonb('fields_payload')->default(DB::raw("'[]'::jsonb"));
            $table->timestampTz('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['questionnaire_template_id', 'version'], 'questionnaire_template_versions_template_version_unique');
            $table->index(['questionnaire_template_id', 'status'], 'questionnaire_template_versions_template_status_index');
        });

        Schema::table('questionnaire_templates', function (Blueprint $table): void {
            $table->foreign('published_version_id')
                ->references('id')
                ->on('questionnaire_template_versions')
                ->nullOnDelete();
        });

        Schema::create('contact_questionnaire_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('questionnaire_template_id')->constrained('questionnaire_templates')->restrictOnDelete();
            $table->foreignId('questionnaire_template_version_id')->constrained('questionnaire_template_versions')->restrictOnDelete();
            $table->string('status', 30)->index();
            $table->string('current_field_key', 100)->nullable();
            $table->foreignId('started_dialog_id')->nullable()->constrained('dialogs')->nullOnDelete();
            $table->foreignId('last_dialog_id')->nullable()->constrained('dialogs')->nullOnDelete();
            $table->string('started_by_block_id', 100)->nullable();
            $table->string('awaiting_block_id', 100)->nullable();
            $table->foreignId('scenario_run_id')->nullable()->constrained('scenario_runs')->nullOnDelete();
            $table->timestampTz('started_at')->nullable()->index();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('operator_requested_at')->nullable();
            $table->timestampTz('reset_at')->nullable();
            $table->foreignId('reset_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['contact_id', 'status'], 'contact_questionnaire_runs_contact_status_index');
            $table->index(['questionnaire_template_id', 'status'], 'contact_questionnaire_runs_template_status_index');
            $table->index(['scenario_run_id', 'awaiting_block_id'], 'contact_questionnaire_runs_scenario_block_index');
        });

        DB::statement(
            "CREATE UNIQUE INDEX contact_questionnaire_runs_one_awaiting_contact_unique
            ON contact_questionnaire_runs (contact_id)
            WHERE status = '".ContactQuestionnaireRun::STATUS_AWAITING_ANSWER."'"
        );

        DB::statement(
            "CREATE UNIQUE INDEX contact_questionnaire_runs_active_template_unique
            ON contact_questionnaire_runs (contact_id, questionnaire_template_id)
            WHERE status IN ('".ContactQuestionnaireRun::STATUS_IN_PROGRESS."', '".ContactQuestionnaireRun::STATUS_AWAITING_ANSWER."')"
        );

        Schema::create('contact_questionnaire_answers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('questionnaire_run_id')->constrained('contact_questionnaire_runs')->cascadeOnDelete();
            $table->string('field_key', 100);
            $table->string('status', 30)->index();
            $table->unsignedInteger('attempts_count')->default(0);
            $table->text('value')->nullable();
            $table->text('display_value')->nullable();
            $table->string('target', 100)->nullable();
            $table->timestampTz('synced_to_contact_at')->nullable();
            $table->timestamps();

            $table->unique(['questionnaire_run_id', 'field_key'], 'contact_questionnaire_answers_run_field_unique');
            $table->index(['field_key', 'status'], 'contact_questionnaire_answers_field_status_index');
        });

        Schema::create('contact_questionnaire_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('questionnaire_run_id')->constrained('contact_questionnaire_runs')->cascadeOnDelete();
            $table->string('field_key', 100);
            $table->unsignedInteger('attempt_index');
            $table->foreignId('dialog_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->text('prompt_text')->nullable();
            $table->text('raw_answer')->nullable();
            $table->text('parsed_value')->nullable();
            $table->string('status', 30)->index();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['questionnaire_run_id', 'field_key', 'attempt_index'], 'contact_questionnaire_attempts_run_field_attempt_unique');
            $table->index(['field_key', 'status'], 'contact_questionnaire_attempts_field_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_questionnaire_attempts');
        Schema::dropIfExists('contact_questionnaire_answers');

        DB::statement('DROP INDEX IF EXISTS contact_questionnaire_runs_active_template_unique');
        DB::statement('DROP INDEX IF EXISTS contact_questionnaire_runs_one_awaiting_contact_unique');

        Schema::dropIfExists('contact_questionnaire_runs');

        Schema::table('questionnaire_templates', function (Blueprint $table): void {
            $table->dropForeign(['published_version_id']);
        });

        Schema::dropIfExists('questionnaire_template_versions');
        Schema::dropIfExists('questionnaire_templates');
    }
};

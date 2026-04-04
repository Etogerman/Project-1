<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_reply_rule_tag_conditions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('auto_reply_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->string('condition', 32);
            $table->timestamps();

            $table->unique(['auto_reply_rule_id', 'tag_id', 'condition'], 'auto_reply_rule_tag_conditions_unique');
            $table->index(['auto_reply_rule_id', 'condition']);
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement(<<<'SQL'
                ALTER TABLE auto_reply_rule_tag_conditions
                ADD CONSTRAINT auto_reply_rule_tag_conditions_condition_check
                CHECK (condition IN ('required', 'excluded'))
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_reply_rule_tag_conditions');
    }
};

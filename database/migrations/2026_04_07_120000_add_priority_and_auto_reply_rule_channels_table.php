<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_reply_rules', function (Blueprint $table): void {
            $table->integer('priority')->default(10)->after('is_active');
        });

        Schema::create('auto_reply_rule_channels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('auto_reply_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->string('button_type')->nullable();
            $table->string('button_text')->nullable();
            $table->text('button_url')->nullable();
            $table->timestamps();

            $table->unique(['auto_reply_rule_id', 'channel_id']);
            $table->index('channel_id');
        });

        $timestamp = now();

        $rows = DB::table('auto_reply_rules')
            ->join('channels', 'channels.id', '=', 'auto_reply_rules.channel_id')
            ->select([
                'auto_reply_rules.id as auto_reply_rule_id',
                'auto_reply_rules.channel_id',
                'channels.platform',
                'auto_reply_rules.telegram_button_type',
                'auto_reply_rules.max_button_type',
            ])
            ->orderBy('auto_reply_rules.id')
            ->get()
            ->map(function (object $rule) use ($timestamp): array {
                $buttonType = match ($rule->platform) {
                    'telegram' => $rule->telegram_button_type === 'request_phone' ? 'share_contact' : null,
                    'max' => $rule->max_button_type === 'request_phone' ? 'share_contact' : null,
                    default => null,
                };

                return [
                    'auto_reply_rule_id' => $rule->auto_reply_rule_id,
                    'channel_id' => $rule->channel_id,
                    'button_type' => $buttonType,
                    'button_text' => null,
                    'button_url' => null,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            })
            ->all();

        if ($rows !== []) {
            DB::table('auto_reply_rule_channels')->insert($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_reply_rule_channels');

        Schema::table('auto_reply_rules', function (Blueprint $table): void {
            $table->dropColumn('priority');
        });
    }
};

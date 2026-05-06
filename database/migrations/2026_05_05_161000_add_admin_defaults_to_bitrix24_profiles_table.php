<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bitrix24_profiles', function (Blueprint $table): void {
            $table->unsignedBigInteger('default_assigned_user_id')->nullable()->after('max_connector_code');
            $table->unsignedBigInteger('default_deal_category_id')->nullable()->after('default_assigned_user_id');
            $table->string('default_deal_stage_id')->nullable()->after('default_deal_category_id');
        });

        DB::table('bitrix24_profiles')->update([
            'default_assigned_user_id' => $this->nullableInteger(config('bitrix24.defaults.assigned_user_id')),
            'default_deal_category_id' => $this->nullableInteger(config('bitrix24.defaults.deal_category_id')),
            'default_deal_stage_id' => $this->nullableString(config('bitrix24.defaults.deal_stage_id')),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('bitrix24_profiles', function (Blueprint $table): void {
            $table->dropColumn([
                'default_assigned_user_id',
                'default_deal_category_id',
                'default_deal_stage_id',
            ]);
        });
    }

    private function nullableInteger(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $integer = (int) $value;

        return $integer >= 0 ? $integer : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
};

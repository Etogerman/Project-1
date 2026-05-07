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
            $table->string('crm_field_name_source')->nullable()->after('default_deal_stage_id');
            $table->string('crm_field_age_exact')->nullable()->after('crm_field_name_source');
            $table->string('crm_field_gender')->nullable()->after('crm_field_age_exact');
            $table->string('crm_field_age_range')->nullable()->after('crm_field_gender');
            $table->string('crm_field_contact_id')->nullable()->after('crm_field_age_range');
            $table->string('crm_field_channel_id')->nullable()->after('crm_field_contact_id');
            $table->string('crm_field_channel_name')->nullable()->after('crm_field_channel_id');
            $table->string('crm_field_platform')->nullable()->after('crm_field_channel_name');
            $table->string('crm_field_bot_code')->nullable()->after('crm_field_platform');
            $table->string('crm_field_bot_name')->nullable()->after('crm_field_bot_code');
            $table->string('crm_field_alt_first_name')->nullable()->after('crm_field_bot_name');
            $table->string('crm_field_alt_last_name')->nullable()->after('crm_field_alt_first_name');
            $table->string('crm_field_name_conflict')->nullable()->after('crm_field_alt_last_name');
            $table->unsignedBigInteger('crm_name_source_automatic_id')->nullable()->after('crm_field_name_conflict');
            $table->unsignedBigInteger('crm_name_source_self_reported_id')->nullable()->after('crm_name_source_automatic_id');
            $table->unsignedBigInteger('crm_name_source_training_verified_id')->nullable()->after('crm_name_source_self_reported_id');
            $table->unsignedBigInteger('crm_gender_male_id')->nullable()->after('crm_name_source_training_verified_id');
            $table->unsignedBigInteger('crm_gender_female_id')->nullable()->after('crm_gender_male_id');
            $table->unsignedBigInteger('crm_gender_unknown_id')->nullable()->after('crm_gender_female_id');
        });

        DB::table('bitrix24_profiles')->update([
            'crm_field_name_source' => config('bitrix24.fields.name_source'),
            'crm_field_age_exact' => config('bitrix24.fields.age_exact'),
            'crm_field_gender' => config('bitrix24.fields.gender'),
            'crm_field_age_range' => config('bitrix24.fields.age_range'),
            'crm_field_contact_id' => config('bitrix24.fields.contact_id'),
            'crm_field_channel_id' => config('bitrix24.fields.channel_id'),
            'crm_field_channel_name' => config('bitrix24.fields.channel_name'),
            'crm_field_platform' => config('bitrix24.fields.platform'),
            'crm_field_bot_code' => config('bitrix24.fields.bot_code'),
            'crm_field_bot_name' => config('bitrix24.fields.bot_name'),
            'crm_field_alt_first_name' => config('bitrix24.fields.alt_first_name'),
            'crm_field_alt_last_name' => config('bitrix24.fields.alt_last_name'),
            'crm_field_name_conflict' => config('bitrix24.fields.name_conflict'),
            'crm_name_source_automatic_id' => (int) config('bitrix24.values.name_source.automatic_information_id'),
            'crm_name_source_self_reported_id' => (int) config('bitrix24.values.name_source.self_reported_id'),
            'crm_name_source_training_verified_id' => (int) config('bitrix24.values.name_source.training_verified_id'),
            'crm_gender_male_id' => (int) config('bitrix24.values.gender.male_id'),
            'crm_gender_female_id' => (int) config('bitrix24.values.gender.female_id'),
            'crm_gender_unknown_id' => (int) config('bitrix24.values.gender.unknown_id'),
        ]);
    }

    public function down(): void
    {
        Schema::table('bitrix24_profiles', function (Blueprint $table): void {
            $table->dropColumn([
                'crm_field_name_source',
                'crm_field_age_exact',
                'crm_field_gender',
                'crm_field_age_range',
                'crm_field_contact_id',
                'crm_field_channel_id',
                'crm_field_channel_name',
                'crm_field_platform',
                'crm_field_bot_code',
                'crm_field_bot_name',
                'crm_field_alt_first_name',
                'crm_field_alt_last_name',
                'crm_field_name_conflict',
                'crm_name_source_automatic_id',
                'crm_name_source_self_reported_id',
                'crm_name_source_training_verified_id',
                'crm_gender_male_id',
                'crm_gender_female_id',
                'crm_gender_unknown_id',
            ]);
        });
    }
};

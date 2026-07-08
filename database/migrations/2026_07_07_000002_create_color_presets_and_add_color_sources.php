<?php

use App\Support\Colors\AbColorPalette;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('color_presets', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('name');
            $table->string('hex', 7);
            $table->string('source', 32)->index();
            $table->boolean('is_recommended')->default(false)->index();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['source', 'hex']);
        });

        $now = now();

        foreach (AbColorPalette::presets() as $preset) {
            DB::table('color_presets')->insert([
                ...$preset,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->addColorColumns('dialog_stages', after: 'color');
        $this->addColorColumns('tags', after: 'color');
        $this->addColorColumns('auto_reply_categories', after: 'sort_order', includeLegacyColor: true);

        $this->backfillLegacyColor('dialog_stages');
        $this->backfillLegacyColor('tags');

        if (Schema::hasTable('auto_reply_categories')) {
            DB::table('auto_reply_categories')->update([
                'color' => 'gray',
                'color_source' => AbColorPalette::ENTITY_SOURCE_PRESET,
                'color_value' => AbColorPalette::DEFAULT_PRESET_KEY,
            ]);
        }
    }

    public function down(): void
    {
        $this->dropColorColumns('auto_reply_categories', dropLegacyColor: true);
        $this->dropColorColumns('tags');
        $this->dropColorColumns('dialog_stages');

        Schema::dropIfExists('color_presets');
    }

    private function addColorColumns(string $tableName, string $after, bool $includeLegacyColor = false): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        $hasLegacyColor = Schema::hasColumn($tableName, 'color');
        $hasColorSource = Schema::hasColumn($tableName, 'color_source');
        $hasColorValue = Schema::hasColumn($tableName, 'color_value');

        Schema::table($tableName, function (Blueprint $table) use ($after, $hasColorSource, $hasColorValue, $hasLegacyColor, $includeLegacyColor): void {
            if ($includeLegacyColor && ! $hasLegacyColor) {
                $table->string('color', 32)->default('gray')->after($after);
            }

            if (! $hasColorSource) {
                $table->string('color_source', 32)
                    ->default(AbColorPalette::ENTITY_SOURCE_PRESET)
                    ->after($includeLegacyColor ? 'color' : $after);
            }

            if (! $hasColorValue) {
                $table->string('color_value', 64)
                    ->default(AbColorPalette::DEFAULT_PRESET_KEY)
                    ->after('color_source');
            }
        });
    }

    private function dropColorColumns(string $tableName, bool $dropLegacyColor = false): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        $hasLegacyColor = Schema::hasColumn($tableName, 'color');
        $hasColorSource = Schema::hasColumn($tableName, 'color_source');
        $hasColorValue = Schema::hasColumn($tableName, 'color_value');

        Schema::table($tableName, function (Blueprint $table) use ($dropLegacyColor, $hasColorSource, $hasColorValue, $hasLegacyColor): void {
            if ($hasColorValue) {
                $table->dropColumn('color_value');
            }

            if ($hasColorSource) {
                $table->dropColumn('color_source');
            }

            if ($dropLegacyColor && $hasLegacyColor) {
                $table->dropColumn('color');
            }
        });
    }

    private function backfillLegacyColor(string $tableName): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        $aliases = AbColorPalette::legacyAliases();

        DB::table($tableName)
            ->select(['id', 'color'])
            ->orderBy('id')
            ->get()
            ->each(function (object $record) use ($aliases, $tableName): void {
                $presetKey = $aliases[(string) $record->color] ?? AbColorPalette::DEFAULT_PRESET_KEY;

                DB::table($tableName)
                    ->where('id', $record->id)
                    ->update([
                        'color_source' => AbColorPalette::ENTITY_SOURCE_PRESET,
                        'color_value' => $presetKey,
                    ]);
            });
    }
};

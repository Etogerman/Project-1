<?php

namespace Tests\Feature;

use App\Models\AutoReplyCategory;
use App\Models\ColorPreset;
use App\Models\DialogStage;
use App\Models\Tag;
use App\Services\Colors\ColorRegistry;
use App\Support\Colors\AbColorPalette;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ColorRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_color_presets_seed_recommended_and_web_safe_catalogs(): void
    {
        $this->assertSame(24, ColorPreset::query()
            ->where('source', AbColorPalette::PRESET_SOURCE_RECOMMENDED)
            ->where('is_recommended', true)
            ->count());

        $this->assertSame(216, ColorPreset::query()
            ->where('source', AbColorPalette::PRESET_SOURCE_WEB_SAFE)
            ->count());

        $this->assertDatabaseHas('color_presets', [
            'key' => 'ab_blue',
            'hex' => '#3366CC',
            'source' => AbColorPalette::PRESET_SOURCE_RECOMMENDED,
        ]);

        $this->assertDatabaseHas('color_presets', [
            'key' => 'web_safe_33ccff',
            'hex' => '#33CCFF',
            'source' => AbColorPalette::PRESET_SOURCE_WEB_SAFE,
        ]);
    }

    public function test_color_registry_normalizes_legacy_and_custom_colors(): void
    {
        $registry = app(ColorRegistry::class);

        $legacy = $registry->normalizeForStorage(null, null, 'warning');
        $custom = $registry->normalizeForStorage(null, '#1a2b3c');

        $this->assertSame([
            'color_source' => AbColorPalette::ENTITY_SOURCE_PRESET,
            'color_value' => 'ab_amber',
            'legacy_color' => 'warning',
        ], $legacy);

        $this->assertSame([
            'color_source' => AbColorPalette::ENTITY_SOURCE_CUSTOM,
            'color_value' => '#1A2B3C',
            'legacy_color' => 'gray',
        ], $custom);
    }

    public function test_color_entities_keep_legacy_tone_and_store_new_color_reference(): void
    {
        $tag = Tag::factory()->create([
            'color' => Tag::COLOR_SUCCESS,
        ]);
        $stage = DialogStage::factory()->create([
            'color' => '#1A2B3C',
        ]);
        $category = AutoReplyCategory::factory()->create([
            'color' => 'ab_violet',
        ]);

        $this->assertSame(Tag::COLOR_SUCCESS, $tag->color);
        $this->assertSame('ab_emerald', $tag->color_value);

        $this->assertSame('gray', $stage->color);
        $this->assertSame(AbColorPalette::ENTITY_SOURCE_CUSTOM, $stage->color_source);
        $this->assertSame('#1A2B3C', $stage->color_value);

        $this->assertSame('primary', $category->color);
        $this->assertSame('ab_violet', $category->color_value);
    }
}

<?php

namespace App\Services\AutoReplyRules;

use App\Models\AutoReplyCategory;
use App\Models\AutoReplyRule;
use App\Models\AutoReplyRuleTagCondition;
use App\Models\AutoReplyRuleTagEffect;
use App\Models\Channel;
use App\Models\Tag;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ExportAutoReplyRulesWorkbookAction
{
    public function handle(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();

        $this->buildRulesSheet($spreadsheet->getActiveSheet());
        $this->buildCategoriesSheet($spreadsheet);
        $this->buildChannelsSheet($spreadsheet);
        $this->buildTagsSheet($spreadsheet);
        $this->buildInstructionsSheet($spreadsheet);

        return $spreadsheet;
    }

    protected function buildRulesSheet(Worksheet $sheet): void
    {
        $sheet->setTitle(AutoReplyRuleWorkbookFormat::SHEET_RULES);
        $sheet->fromArray([AutoReplyRuleWorkbookFormat::rulesColumns()], null, 'A1');

        $rules = AutoReplyRule::query()
            ->with(['category', 'channels', 'tagConditions.tag', 'tagEffects.tag'])
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        $rowIndex = 2;

        foreach ($rules as $rule) {
            $sheet->fromArray([$this->buildRuleRow($rule)], null, 'A'.$rowIndex);
            $rowIndex++;
        }
    }

    protected function buildCategoriesSheet(Spreadsheet $spreadsheet): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle(AutoReplyRuleWorkbookFormat::SHEET_CATEGORIES);
        $sheet->fromArray([['id', 'name', 'sort_order']], null, 'A1');

        $categories = AutoReplyCategory::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $rowIndex = 2;

        foreach ($categories as $category) {
            $sheet->fromArray([[
                $category->id,
                $category->name,
                $category->sort_order,
            ]], null, 'A'.$rowIndex);
            $rowIndex++;
        }
    }

    protected function buildChannelsSheet(Spreadsheet $spreadsheet): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle(AutoReplyRuleWorkbookFormat::SHEET_CHANNELS);
        $sheet->fromArray([['id', 'name', 'platform']], null, 'A1');

        $channels = Channel::query()
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $rowIndex = 2;

        foreach ($channels as $channel) {
            $sheet->fromArray([[
                $channel->id,
                $channel->name,
                $channel->platform,
            ]], null, 'A'.$rowIndex);
            $rowIndex++;
        }
    }

    protected function buildTagsSheet(Spreadsheet $spreadsheet): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle(AutoReplyRuleWorkbookFormat::SHEET_TAGS);
        $sheet->fromArray([['id', 'name']], null, 'A1');

        $tags = Tag::query()
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $rowIndex = 2;

        foreach ($tags as $tag) {
            $sheet->fromArray([[
                $tag->id,
                $tag->name,
            ]], null, 'A'.$rowIndex);
            $rowIndex++;
        }
    }

    protected function buildInstructionsSheet(Spreadsheet $spreadsheet): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle(AutoReplyRuleWorkbookFormat::SHEET_INSTRUCTIONS);
        $sheet->fromArray([['rule']], null, 'A1');

        $rowIndex = 2;

        foreach (AutoReplyRuleWorkbookFormat::instructionLines() as $line) {
            $sheet->fromArray([[$line]], null, 'A'.$rowIndex);
            $rowIndex++;
        }
    }

    /**
     * @return list<string|int|null>
     */
    protected function buildRuleRow(AutoReplyRule $rule): array
    {
        $buttonConfig = $this->resolveExportButtonConfig($rule);

        return [
            $rule->id,
            $rule->name,
            $rule->category?->name,
            $rule->is_active ? '1' : '0',
            $rule->priority,
            $rule->match_scope,
            $rule->keyword,
            $rule->contact_phone_condition,
            $rule->reply_text,
            $buttonConfig['button_kind'],
            $buttonConfig['button_text'],
            $buttonConfig['button_url'],
            AutoReplyRuleWorkbookFormat::formatList($this->resolveChannelIds($rule)),
            AutoReplyRuleWorkbookFormat::formatList($this->resolveConditionTagNames($rule, AutoReplyRuleTagCondition::CONDITION_REQUIRED)),
            AutoReplyRuleWorkbookFormat::formatList($this->resolveConditionTagNames($rule, AutoReplyRuleTagCondition::CONDITION_EXCLUDED)),
            AutoReplyRuleWorkbookFormat::formatList($this->resolveEffectTagNames($rule, AutoReplyRuleTagEffect::EFFECT_ASSIGN)),
            AutoReplyRuleWorkbookFormat::formatList($this->resolveEffectTagNames($rule, AutoReplyRuleTagEffect::EFFECT_REMOVE)),
        ];
    }

    /**
     * @return array{button_kind:string,button_text:?string,button_url:?string}
     */
    protected function resolveExportButtonConfig(AutoReplyRule $rule): array
    {
        $rule->loadMissing('channels');

        /** @var Channel|null $primaryChannel */
        $primaryChannel = $rule->channels->firstWhere('id', (int) $rule->channel_id);

        /** @var Channel|null $buttonSourceChannel */
        $buttonSourceChannel = $primaryChannel instanceof Channel
            ? $primaryChannel
            : $rule->channels
            ->sortBy('id')
            ->first();

        if (! $buttonSourceChannel instanceof Channel) {
            return [
                'button_kind' => AutoReplyRuleWorkbookFormat::BUTTON_KIND_NONE,
                'button_text' => null,
                'button_url' => null,
            ];
        }

        $buttonType = $rule->getButtonTypeForChannel($buttonSourceChannel);

        return match ($buttonType) {
            AutoReplyRule::BUTTON_TYPE_SHARE_CONTACT => [
                'button_kind' => AutoReplyRuleWorkbookFormat::BUTTON_KIND_REQUEST_PHONE,
                'button_text' => null,
                'button_url' => null,
            ],
            AutoReplyRule::BUTTON_TYPE_INLINE_KEYBOARD => [
                'button_kind' => AutoReplyRuleWorkbookFormat::BUTTON_KIND_LINK,
                'button_text' => $rule->getButtonTextForChannel($buttonSourceChannel),
                'button_url' => $rule->getButtonUrlForChannel($buttonSourceChannel),
            ],
            default => [
                'button_kind' => AutoReplyRuleWorkbookFormat::BUTTON_KIND_NONE,
                'button_text' => null,
                'button_url' => null,
            ],
        };
    }

    /**
     * @return list<string>
     */
    protected function resolveChannelIds(AutoReplyRule $rule): array
    {
        $rule->loadMissing('channels');

        $channelIds = $rule->channels
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->sort()
            ->values()
            ->all();

        $primaryChannelId = (int) $rule->channel_id;

        if ($primaryChannelId > 0 && in_array($primaryChannelId, $channelIds, true)) {
            $channelIds = [
                $primaryChannelId,
                ...array_values(array_filter(
                    $channelIds,
                    fn (int $channelId): bool => $channelId !== $primaryChannelId,
                )),
            ];
        }

        return array_map(
            fn (int $channelId): string => (string) $channelId,
            $channelIds,
        );
    }

    /**
     * @return list<string>
     */
    protected function resolveConditionTagNames(AutoReplyRule $rule, string $condition): array
    {
        $rule->loadMissing('tagConditions.tag');

        return $rule->tagConditions
            ->where('condition', $condition)
            ->map(fn (AutoReplyRuleTagCondition $tagCondition): string => (string) $tagCondition->tag?->name)
            ->filter(fn (?string $name): bool => filled($name))
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    protected function resolveEffectTagNames(AutoReplyRule $rule, string $effect): array
    {
        $rule->loadMissing('tagEffects.tag');

        return $rule->tagEffects
            ->where('effect', $effect)
            ->map(fn (AutoReplyRuleTagEffect $tagEffect): string => (string) $tagEffect->tag?->name)
            ->filter(fn (?string $name): bool => filled($name))
            ->sort()
            ->values()
            ->all();
    }
}

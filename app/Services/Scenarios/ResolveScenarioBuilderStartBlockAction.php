<?php

namespace App\Services\Scenarios;

use App\Models\AutoReplyRule;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ScenarioBuilderBlock;
use App\Models\ScenarioBuilderCondition;
use App\Models\ScenarioVersion;

class ResolveScenarioBuilderStartBlockAction
{
    public function handle(
        Channel $channel,
        Contact $contact,
        ?string $messageText,
        ?string $messageParameter = null,
    ): ?ScenarioBuilderBlock {
        $normalizedText = AutoReplyRule::normalizeKeyword($messageText) ?? '';
        $normalizedParameter = AutoReplyRule::normalizeKeyword($messageParameter) ?? '';

        return ScenarioBuilderBlock::query()
            ->with(['channels', 'conditions', 'scenarioVersion.scenario'])
            ->whereHas('channels', fn ($query) => $query->whereKey($channel->id))
            ->where('type', ScenarioBuilderBlock::TYPE_START_CONDITION)
            ->whereHas('scenarioVersion', function ($query): void {
                $query
                    ->where('status', ScenarioVersion::STATUS_DRAFT)
                    ->whereHas('scenario', function ($query): void {
                        $query
                            ->where('is_active', true)
                            ->where('is_archived', false);
                    });
            })
            ->orderBy('scenario_version_id')
            ->orderBy('id')
            ->get()
            ->first(fn (ScenarioBuilderBlock $block): bool => $this->matchesBlock(
                $block,
                $normalizedText,
                $normalizedParameter,
            ));
    }

    private function matchesBlock(
        ScenarioBuilderBlock $block,
        string $normalizedText,
        string $normalizedParameter,
    ): bool {
        $block->loadMissing('conditions');

        $conditions = $block->conditions;
        $fallbackMatch = $this->normalizeMatch(data_get($block->settings_payload, 'condition.match'));

        if ($fallbackMatch === AutoReplyRule::MATCH_SCOPE_ANY_INBOUND && $conditions->isEmpty()) {
            return true;
        }

        foreach ($conditions as $condition) {
            /** @var ScenarioBuilderCondition $condition */
            if ($this->matchesCondition($condition, $normalizedText, $normalizedParameter)) {
                return true;
            }
        }

        return false;
    }

    private function matchesCondition(
        ScenarioBuilderCondition $condition,
        string $normalizedText,
        string $normalizedParameter,
    ): bool {
        $match = $this->normalizeMatch($condition->match_operator);
        $value = AutoReplyRule::normalizeKeyword($condition->value);

        if ($match === AutoReplyRule::MATCH_SCOPE_ANY_INBOUND) {
            return true;
        }

        if (! filled($value)) {
            return false;
        }

        return match ($match) {
            AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER => $normalizedParameter === $value,
            AutoReplyRule::MATCH_SCOPE_EXACT_TEXT_OR_PARAMETER => $normalizedText === $value || $normalizedParameter === $value,
            AutoReplyRule::MATCH_SCOPE_CONTAINS_TEXT => filled($normalizedText) && str_contains($normalizedText, $value),
            default => $normalizedText === $value,
        };
    }

    private function normalizeMatch(mixed $value): string
    {
        $normalizedMatch = is_string($value) ? trim($value) : '';

        return array_key_exists($normalizedMatch, AutoReplyRule::matchScopeOptions())
            ? $normalizedMatch
            : AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD;
    }
}

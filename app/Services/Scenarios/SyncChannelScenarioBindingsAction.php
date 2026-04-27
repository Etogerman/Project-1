<?php

namespace App\Services\Scenarios;

use App\Models\Channel;
use App\Models\Scenario;
use App\Models\ScenarioChannelBinding;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SyncChannelScenarioBindingsAction
{
    public function __construct(
        private readonly ScenarioRegistry $scenarioRegistry,
    ) {}

    /**
     * @param  array<int, mixed>  $selectedScenarioCodes
     */
    public function handle(Channel $channel, array $selectedScenarioCodes): void
    {
        $compatibleScenarioCodes = $this->scenarioRegistry->compatibleScenarioCodesForChannel($channel);
        $selectableScenarioCodes = $this->scenarioRegistry->selectableScenarioCodesForChannel($channel);
        $normalizedSelectedScenarioCodes = $this->normalizeSelectedScenarioCodes($selectedScenarioCodes);

        foreach ($normalizedSelectedScenarioCodes as $scenarioCode) {
            if (! $this->scenarioRegistry->has($scenarioCode)) {
                throw ValidationException::withMessages([
                    'scenario_codes' => 'Выбран неизвестный сценарий.',
                ]);
            }

            if (! in_array($scenarioCode, $selectableScenarioCodes, true)) {
                throw ValidationException::withMessages([
                    'scenario_codes' => 'Выбранный сценарий недоступен для этого канала.',
                ]);
            }
        }

        DB::transaction(function () use ($channel, $compatibleScenarioCodes, $selectableScenarioCodes, $normalizedSelectedScenarioCodes): void {
            $existingBindings = $channel->scenarioBindings()
                ->get()
                ->keyBy('scenario_code');

            foreach ($selectableScenarioCodes as $scenarioCode) {
                $binding = $existingBindings->get($scenarioCode);
                $shouldBeActive = in_array($scenarioCode, $normalizedSelectedScenarioCodes, true);

                if ($binding instanceof ScenarioChannelBinding) {
                    if ((bool) $binding->is_active !== $shouldBeActive) {
                        $binding->forceFill([
                            'is_active' => $shouldBeActive,
                        ])->save();
                    }

                    continue;
                }

                if (! $shouldBeActive) {
                    continue;
                }

                ScenarioChannelBinding::query()->create([
                    'channel_id' => $channel->id,
                    'scenario_code' => $scenarioCode,
                    'is_active' => true,
                ]);
            }

            foreach ($existingBindings as $scenarioCode => $binding) {
                if (
                    $binding instanceof ScenarioChannelBinding
                    && $scenarioCode !== Scenario::CONSTRUCTOR_WORKSPACE_CODE
                    && $binding->is_active
                    && ! in_array($scenarioCode, $compatibleScenarioCodes, true)
                ) {
                    $binding->forceFill([
                        'is_active' => false,
                    ])->save();
                }
            }
        });
    }

    /**
     * @param  array<int, mixed>  $selectedScenarioCodes
     * @return list<string>
     */
    private function normalizeSelectedScenarioCodes(array $selectedScenarioCodes): array
    {
        $normalizedScenarioCodes = [];

        foreach ($selectedScenarioCodes as $scenarioCode) {
            if (! is_string($scenarioCode)) {
                continue;
            }

            $normalizedScenarioCode = trim($scenarioCode);

            if ($normalizedScenarioCode === '') {
                continue;
            }

            $normalizedScenarioCodes[$normalizedScenarioCode] = $normalizedScenarioCode;
        }

        return array_values($normalizedScenarioCodes);
    }
}

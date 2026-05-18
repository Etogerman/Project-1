<?php

namespace App\Services\Scenarios;

use App\Models\Channel;
use App\Models\Scenario;
use App\Models\ScenarioChannelBinding;
use App\Models\ScenarioVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PublishScenarioBuilderV3Action
{
    public function __construct(
        private readonly BuildScenarioBuilderV3StateAction $buildScenarioBuilderV3StateAction,
        private readonly CompileScenarioBuilderV3RuntimeAction $compileScenarioBuilderV3RuntimeAction,
        private readonly CreateNextScenarioDraftAction $createNextScenarioDraftAction,
    ) {}

    /**
     * @return array{published_version: ScenarioVersion, draft_version: ScenarioVersion}
     */
    public function handle(Scenario $scenario, int $draftVersionId, string $baseRevision, User $user): array
    {
        [$publishedVersion, $draftVersion] = DB::transaction(function () use ($scenario, $draftVersionId, $baseRevision, $user): array {
            $lockedScenario = Scenario::query()
                ->whereKey($scenario->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedScenario instanceof Scenario || (bool) $lockedScenario->is_archived) {
                throw ValidationException::withMessages([
                    'scenario' => 'Нельзя публиковать архивный сценарий.',
                ]);
            }

            $version = ScenarioVersion::query()
                ->whereKey($draftVersionId)
                ->where('scenario_id', $lockedScenario->id)
                ->where('status', ScenarioVersion::STATUS_DRAFT)
                ->lockForUpdate()
                ->first();

            if (! $version instanceof ScenarioVersion) {
                throw ValidationException::withMessages([
                    'draft_version_id' => 'Версия для публикации не найдена.',
                ]);
            }

            $currentRevision = $this->buildScenarioBuilderV3StateAction->revisionFor($version);

            if ($baseRevision !== $currentRevision) {
                throw new HttpException(409, 'Scenario builder state was changed. Save or reload state before publishing.');
            }

            $runtime = $this->compileScenarioBuilderV3RuntimeAction->handle($version, $currentRevision);
            $schemaPayload = is_array($version->schema_payload) ? $version->schema_payload : [];
            $schemaPayload['version'] = BuildScenarioBuilderV3StateAction::SCHEMA_VERSION;
            $schemaPayload['builder_v3_runtime'] = $runtime;

            data_set($schemaPayload, 'builder_v3.published_revision', $currentRevision);

            $this->guardCanManageChannels($this->channelIdsFromRuntime($runtime), $user);

            ScenarioVersion::query()
                ->where('scenario_id', $lockedScenario->id)
                ->where('status', ScenarioVersion::STATUS_PUBLISHED)
                ->update([
                    'status' => ScenarioVersion::STATUS_ARCHIVED,
                    'updated_at' => now(),
                ]);

            $version->forceFill([
                'schema_payload' => $schemaPayload,
                'status' => ScenarioVersion::STATUS_PUBLISHED,
            ])->save();

            $publishedVersion = $version->fresh(['scenario']);

            $this->syncScenarioBindings($publishedVersion, $user);

            $draftVersion = $this->createNextScenarioDraftAction->handle(
                $publishedVersion->scenario()->firstOrFail()->fresh(['publishedVersion', 'draftVersion']),
            );

            return [$publishedVersion->fresh(), $draftVersion->fresh()];
        });

        app(ScenarioRegistry::class)->forgetCachedDefinitions();

        return [
            'published_version' => $publishedVersion->fresh(),
            'draft_version' => $draftVersion->fresh(),
        ];
    }

    private function syncScenarioBindings(ScenarioVersion $publishedVersion, User $user): void
    {
        $scenario = $publishedVersion->scenario()->firstOrFail();
        $channelIds = collect(data_get($publishedVersion->schema_payload, 'builder_v3_runtime.entrypoints', []))
            ->flatMap(fn (mixed $entrypoint): array => is_array($entrypoint)
                ? collect($entrypoint['channel_ids'] ?? [])->map(fn (mixed $id): int => (int) $id)->all()
                : [])
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        $this->guardCanManageChannels($channelIds, $user);

        $manageableChannelIds = Channel::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->filter(fn (Channel $channel): bool => $user->can('update', $channel))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if ($manageableChannelIds !== []) {
            ScenarioChannelBinding::query()
                ->where('scenario_code', $scenario->code)
                ->whereIn('channel_id', $manageableChannelIds)
                ->when($channelIds !== [], fn ($query) => $query->whereNotIn('channel_id', $channelIds))
                ->update([
                    'is_active' => false,
                ]);
        }

        foreach ($channelIds as $channelId) {
            ScenarioChannelBinding::query()->updateOrCreate(
                [
                    'channel_id' => $channelId,
                    'scenario_code' => $scenario->code,
                ],
                [
                    'is_active' => true,
                ],
            );
        }
    }

    /**
     * @param  list<int>  $channelIds
     */
    private function guardCanManageChannels(array $channelIds, User $user): void
    {
        if ($channelIds === []) {
            return;
        }

        $channels = Channel::query()
            ->whereKey($channelIds)
            ->get();

        if ($channels->count() !== count($channelIds)) {
            throw ValidationException::withMessages([
                'builder.start_condition.channels' => 'Выбранный канал не найден.',
            ]);
        }

        if ($channels->contains(fn (Channel $channel): bool => ! (bool) $channel->is_active)) {
            throw ValidationException::withMessages([
                'builder.start_condition.channels' => 'Выбранный канал недоступен.',
            ]);
        }

        if ($channels->contains(fn (Channel $channel): bool => ! $user->can('update', $channel))) {
            throw ValidationException::withMessages([
                'builder.start_condition.channels' => 'Недостаточно прав для публикации выбранных каналов.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $runtime
     * @return list<int>
     */
    private function channelIdsFromRuntime(array $runtime): array
    {
        return collect($runtime['entrypoints'] ?? [])
            ->flatMap(fn (mixed $entrypoint): array => is_array($entrypoint)
                ? collect($entrypoint['channel_ids'] ?? [])->map(fn (mixed $id): int => (int) $id)->all()
                : [])
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }
}

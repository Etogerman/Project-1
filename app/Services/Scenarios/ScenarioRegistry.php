<?php

namespace App\Services\Scenarios;

use App\Models\Scenario;
use App\Models\Channel;
use App\Models\ScenarioVersion;
use App\Services\Scenarios\Adapters\BuiltinScenarioAdapter;
use Illuminate\Support\Facades\Cache;

class ScenarioRegistry
{
    private const DB_DEFINITIONS_CACHE_KEY = 'scenarios:db-definitions:v1';

    /**
     * @return array<string, array{type: 'builtin'|'database', handler: class-string|null, label: string, platforms: list<string>|null}>
     */
    public function definitions(): array
    {
        $resolved = $this->builtInDefinitions();

        foreach ($this->databaseDefinitions() as $scenarioCode => $definition) {
            if (! array_key_exists($scenarioCode, $resolved)) {
                $resolved[$scenarioCode] = $definition;
            }
        }

        return $resolved;
    }

    public function forgetCachedDefinitions(): void
    {
        Cache::forget(self::DB_DEFINITIONS_CACHE_KEY);
    }

    /**
     * @return array<string, class-string|null>
     */
    public function all(): array
    {
        return array_map(
            static fn (array $definition): ?string => $definition['handler'],
            $this->definitions(),
        );
    }

    public function has(?string $scenarioCode): bool
    {
        if (! is_string($scenarioCode)) {
            return false;
        }

        $normalizedScenarioCode = trim($scenarioCode);

        if ($normalizedScenarioCode === '') {
            return false;
        }

        return $this->definition($normalizedScenarioCode) !== null;
    }

    public function handlerClass(?string $scenarioCode): ?string
    {
        return $this->definition($scenarioCode)['handler'] ?? null;
    }

    public function type(?string $scenarioCode): ?string
    {
        return $this->definition($scenarioCode)['type'] ?? null;
    }

    public function label(?string $scenarioCode): ?string
    {
        return $this->definition($scenarioCode)['label'] ?? null;
    }

    public function make(?string $scenarioCode): ?object
    {
        $handlerClass = $this->handlerClass($scenarioCode);

        if (! is_string($handlerClass)) {
            return null;
        }

        return app($handlerClass);
    }

    public function makeRuntime(?string $scenarioCode): ?ResolvedScenarioRuntime
    {
        $definition = $this->definition($scenarioCode);

        if ($definition === null) {
            return null;
        }

        if ($definition['type'] === 'builtin') {
            $handlerClass = $definition['handler'];

            if (! is_string($handlerClass)) {
                return null;
            }

            $handler = app($handlerClass);

            if (! $handler instanceof ScenarioHandler) {
                return null;
            }

            return new BuiltinScenarioAdapter(
                $this->normalizeScenarioCode($scenarioCode),
                $handler,
            );
        }

        $scenario = $this->publishedScenarioModel($this->normalizeScenarioCode($scenarioCode));

        if (! $scenario instanceof Scenario || ! $scenario->publishedVersion instanceof ScenarioVersion) {
            return null;
        }

        return app()->make(GenericDbScenarioRuntime::class, [
            'scenario' => $scenario,
            'publishedVersion' => $scenario->publishedVersion,
        ]);
    }

    /**
     * @return list<string>
     */
    public function compatibleScenarioCodesForChannel(Channel $channel): array
    {
        $compatibleScenarioCodes = [];

        foreach ($this->definitions() as $scenarioCode => $definition) {
            if ($this->supportsChannel($scenarioCode, $channel)) {
                $compatibleScenarioCodes[] = $scenarioCode;
            }
        }

        return $compatibleScenarioCodes;
    }

    /**
     * @return array<string, string>
     */
    public function optionsForChannel(Channel $channel): array
    {
        $options = [];

        foreach ($this->compatibleScenarioCodesForChannel($channel) as $scenarioCode) {
            $options[$scenarioCode] = $this->label($scenarioCode) ?? $scenarioCode;
        }

        return $options;
    }

    public function supportsChannel(?string $scenarioCode, Channel $channel): bool
    {
        $definition = $this->definition($scenarioCode);

        if ($definition === null) {
            return false;
        }

        $platforms = $definition['platforms'];

        if ($platforms === null) {
            return true;
        }

        return in_array($channel->platform, $platforms, true);
    }

    /**
     * @return array{type: 'builtin'|'database', handler: class-string|null, label: string, platforms: list<string>|null}|null
     */
    private function definition(?string $scenarioCode): ?array
    {
        $normalizedScenarioCode = $this->normalizeScenarioCode($scenarioCode);

        if ($normalizedScenarioCode === null) {
            return null;
        }

        return $this->definitions()[$normalizedScenarioCode] ?? null;
    }

    private function normalizeScenarioCode(?string $scenarioCode): ?string
    {
        if (! is_string($scenarioCode)) {
            return null;
        }

        $normalizedScenarioCode = trim($scenarioCode);

        return $normalizedScenarioCode !== '' ? $normalizedScenarioCode : null;
    }

    /**
     * @return array<string, array{type: 'builtin', handler: class-string|null, label: string, platforms: list<string>|null}>
     */
    private function builtInDefinitions(): array
    {
        $configured = config('scenarios', []);

        if (! is_array($configured)) {
            return [];
        }

        $resolved = [];

        foreach ($configured as $scenarioCode => $definition) {
            if (! is_string($scenarioCode)) {
                continue;
            }

            $normalizedScenarioCode = $this->normalizeScenarioCode($scenarioCode);

            if ($normalizedScenarioCode === null) {
                continue;
            }

            $normalizedDefinition = $this->normalizeDefinition($definition);

            if ($normalizedDefinition === null) {
                continue;
            }

            $resolved[$normalizedScenarioCode] = $normalizedDefinition;
        }

        return $resolved;
    }

    /**
     * @return array<string, array{type: 'database', handler: null, label: string, platforms: null}>
     */
    private function databaseDefinitions(): array
    {
        if (app()->runningUnitTests()) {
            return $this->queryDatabaseDefinitions();
        }

        /** @var array<string, array{type: 'database', handler: null, label: string, platforms: null}> $definitions */
        $definitions = Cache::rememberForever(
            self::DB_DEFINITIONS_CACHE_KEY,
            fn (): array => $this->queryDatabaseDefinitions(),
        );

        return $definitions;
    }

    /**
     * @return array<string, array{type: 'database', handler: null, label: string, platforms: null}>
     */
    private function queryDatabaseDefinitions(): array
    {
        return Scenario::query()
            ->where('is_active', true)
            ->where('is_archived', false)
            ->whereHas('publishedVersion')
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (Scenario $scenario): array => [
                (string) $scenario->code => [
                    'type' => 'database',
                    'handler' => null,
                    'label' => (string) $scenario->name,
                    'platforms' => null,
                ],
            ])
            ->all();
    }

    /**
     * @return array{type: 'builtin', handler: class-string|null, label: string, platforms: list<string>|null}|null
     */
    private function normalizeDefinition(mixed $definition): ?array
    {
        $handlerClass = null;
        $label = null;
        $platforms = null;

        if (is_string($definition)) {
            $handlerClass = trim($definition);
        } elseif (is_array($definition)) {
            $rawHandler = $definition['handler'] ?? null;

            if (is_string($rawHandler)) {
                $handlerClass = trim($rawHandler);
            }

            $rawLabel = $definition['label'] ?? null;

            if (is_string($rawLabel)) {
                $label = trim($rawLabel);
            }

            $rawPlatforms = $definition['platforms'] ?? null;

            if (is_array($rawPlatforms)) {
                $platforms = array_values(array_filter(
                    array_map(
                        static fn (mixed $platform): ?string => is_string($platform) && trim($platform) !== ''
                            ? trim($platform)
                            : null,
                        $rawPlatforms,
                    ),
                    static fn (?string $platform): bool => $platform !== null,
                ));
            }
        }

        if (! is_string($handlerClass) || $handlerClass === '' || ! class_exists($handlerClass)) {
            return null;
        }

        return [
            'type' => 'builtin',
            'handler' => $handlerClass,
            'label' => is_string($label) && $label !== '' ? $label : class_basename($handlerClass),
            'platforms' => $platforms === [] ? null : $platforms,
        ];
    }

    private function publishedScenarioModel(string $scenarioCode): ?Scenario
    {
        return Scenario::query()
            ->with('publishedVersion')
            ->where('code', $scenarioCode)
            ->where('is_active', true)
            ->where('is_archived', false)
            ->whereHas('publishedVersion')
            ->first();
    }
}

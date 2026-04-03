<?php

namespace App\Services\Scenarios;

use App\Models\Channel;

class ScenarioRegistry
{
    /**
     * @return array<string, array{handler: class-string, platforms: list<string>|null}>
     */
    public function definitions(): array
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

            $normalizedScenarioCode = trim($scenarioCode);

            if ($normalizedScenarioCode === '') {
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
     * @return array<string, class-string>
     */
    public function all(): array
    {
        return array_map(
            static fn (array $definition): string => $definition['handler'],
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

        return array_key_exists($normalizedScenarioCode, $this->all());
    }

    public function handlerClass(?string $scenarioCode): ?string
    {
        return $this->definition($scenarioCode)['handler'] ?? null;
    }

    public function make(?string $scenarioCode): ?object
    {
        $handlerClass = $this->handlerClass($scenarioCode);

        if (! is_string($handlerClass)) {
            return null;
        }

        return app($handlerClass);
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
            $options[$scenarioCode] = $scenarioCode;
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
     * @return array{handler: class-string, platforms: list<string>|null}|null
     */
    private function definition(?string $scenarioCode): ?array
    {
        if (! is_string($scenarioCode)) {
            return null;
        }

        $normalizedScenarioCode = trim($scenarioCode);

        if ($normalizedScenarioCode === '') {
            return null;
        }

        return $this->definitions()[$normalizedScenarioCode] ?? null;
    }

    /**
     * @return array{handler: class-string, platforms: list<string>|null}|null
     */
    private function normalizeDefinition(mixed $definition): ?array
    {
        $handlerClass = null;
        $platforms = null;

        if (is_string($definition)) {
            $handlerClass = trim($definition);
        } elseif (is_array($definition)) {
            $rawHandler = $definition['handler'] ?? null;

            if (is_string($rawHandler)) {
                $handlerClass = trim($rawHandler);
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
            'handler' => $handlerClass,
            'platforms' => $platforms === [] ? null : $platforms,
        ];
    }
}

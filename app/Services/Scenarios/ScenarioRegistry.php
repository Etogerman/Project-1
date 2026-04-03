<?php

namespace App\Services\Scenarios;

class ScenarioRegistry
{
    /**
     * @return array<string, class-string>
     */
    public function all(): array
    {
        $configured = config('scenarios', []);

        if (! is_array($configured)) {
            return [];
        }

        $resolved = [];

        foreach ($configured as $scenarioCode => $handlerClass) {
            if (! is_string($scenarioCode) || ! is_string($handlerClass)) {
                continue;
            }

            $normalizedScenarioCode = trim($scenarioCode);
            $normalizedHandlerClass = trim($handlerClass);

            if ($normalizedScenarioCode === '' || $normalizedHandlerClass === '' || ! class_exists($normalizedHandlerClass)) {
                continue;
            }

            $resolved[$normalizedScenarioCode] = $normalizedHandlerClass;
        }

        return $resolved;
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
        if (! is_string($scenarioCode)) {
            return null;
        }

        $normalizedScenarioCode = trim($scenarioCode);

        if ($normalizedScenarioCode === '') {
            return null;
        }

        return $this->all()[$normalizedScenarioCode] ?? null;
    }

    public function make(?string $scenarioCode): ?object
    {
        $handlerClass = $this->handlerClass($scenarioCode);

        if (! is_string($handlerClass)) {
            return null;
        }

        return app($handlerClass);
    }
}

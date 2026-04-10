<?php

namespace App\Services\Scenarios;

class ScenarioConditionEvaluator
{
    /**
     * @param  array<string, mixed>  $condition
     * @param  array<string, mixed>  $statePayload
     */
    public function handle(array $condition, array $statePayload): bool
    {
        if (array_key_exists('all', $condition)) {
            foreach ($condition['all'] as $nestedCondition) {
                if (! $this->handle($nestedCondition, $statePayload)) {
                    return false;
                }
            }

            return true;
        }

        if (array_key_exists('any', $condition)) {
            foreach ($condition['any'] as $nestedCondition) {
                if ($this->handle($nestedCondition, $statePayload)) {
                    return true;
                }
            }

            return false;
        }

        if (array_key_exists('not', $condition)) {
            return ! $this->handle($condition['not'], $statePayload);
        }

        $actualValue = data_get($statePayload, (string) ($condition['var'] ?? ''));

        if (array_key_exists('equals', $condition)) {
            return $actualValue === $condition['equals'];
        }

        if (array_key_exists('not_equals', $condition)) {
            return $actualValue !== $condition['not_equals'];
        }

        if (array_key_exists('in', $condition)) {
            return in_array($actualValue, $condition['in'], true);
        }

        if (array_key_exists('not_in', $condition)) {
            return ! in_array($actualValue, $condition['not_in'], true);
        }

        return false;
    }
}

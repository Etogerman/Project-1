<?php

namespace App\Data\Bitrix24;

use App\Models\Bitrix24Profile;

final readonly class Bitrix24DevProfileBootstrapResultData
{
    public const STATUS_OK = 'ok';

    public const STATUS_MISSING = 'missing';

    public const STATUS_WARNING = 'warning';

    /**
     * @param  list<array{label: string, required: bool, status: string, value: string, notes: string}>  $checks
     * @param  list<string>  $instructionSteps
     */
    public function __construct(
        public Bitrix24Profile $profile,
        public bool $created,
        public array $checks,
        public array $instructionSteps,
    ) {}

    public function hasBlockingIssues(): bool
    {
        foreach ($this->checks as $check) {
            if ($check['required'] && $check['status'] === self::STATUS_MISSING) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{Item: string, Required: string, Status: string, Value: string, Notes: string}>
     */
    public function checkTableRows(): array
    {
        return array_map(
            fn (array $check): array => [
                'Item' => $check['label'],
                'Required' => $check['required'] ? 'yes' : 'no',
                'Status' => $check['status'],
                'Value' => $this->displayValue($check['value']),
                'Notes' => $check['notes'],
            ],
            $this->checks,
        );
    }

    /**
     * @return list<array{Field: string, Value: string}>
     */
    public function profileRows(): array
    {
        return [
            ['Field' => 'portal_domain', 'Value' => $this->displayValue($this->profile->portal_domain)],
            ['Field' => 'profile_key', 'Value' => $this->displayValue($this->profile->profile_key)],
            ['Field' => 'profile_type', 'Value' => $this->displayValue($this->profile->profile_type)],
            ['Field' => 'display_name', 'Value' => $this->displayValue($this->profile->display_name)],
            ['Field' => 'callback_base_url', 'Value' => $this->displayValue($this->profile->callback_base_url)],
            ['Field' => 'client_id', 'Value' => $this->displayValue($this->profile->client_id)],
            ['Field' => 'application_code', 'Value' => $this->displayValue($this->profile->application_code)],
            ['Field' => 'telegram_source_id', 'Value' => $this->displayValue($this->profile->telegram_source_id)],
            ['Field' => 'max_source_id', 'Value' => $this->displayValue($this->profile->max_source_id)],
            ['Field' => 'telegram_connector_code', 'Value' => $this->displayValue($this->profile->telegram_connector_code)],
            ['Field' => 'max_connector_code', 'Value' => $this->displayValue($this->profile->max_connector_code)],
        ];
    }

    /**
     * @return list<array{Callback: string, Value: string}>
     */
    public function callbackRows(): array
    {
        return [
            ['Callback' => 'install_url', 'Value' => $this->profile->installCallbackUrl()],
            ['Callback' => 'events_url', 'Value' => $this->profile->eventsCallbackUrl()],
            ['Callback' => 'openlines_url', 'Value' => $this->profile->openlinesCallbackUrl()],
        ];
    }

    private function displayValue(?string $value): string
    {
        $trimmed = is_string($value) ? trim($value) : '';

        return $trimmed === '' ? '—' : $trimmed;
    }
}

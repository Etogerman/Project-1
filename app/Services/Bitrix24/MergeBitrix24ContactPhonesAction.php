<?php

namespace App\Services\Bitrix24;

use App\Services\Contacts\NormalizePhoneNumberAction;

class MergeBitrix24ContactPhonesAction
{
    public function __construct(
        private readonly NormalizePhoneNumberAction $normalizePhoneNumberAction,
    ) {}

    /**
     * @param  list<string>  $localPhones
     * @param  list<array{value: string, normalized: string, value_type: string}>  $remotePhones
     * @return list<array{VALUE: string, VALUE_TYPE: string}>
     */
    public function handle(array $localPhones, array $remotePhones): array
    {
        $mergedPhones = [];

        foreach ($localPhones as $index => $phone) {
            $normalizedPhone = $this->normalizePhoneNumberAction->handle($phone);

            if ($normalizedPhone === '' || isset($mergedPhones[$normalizedPhone])) {
                continue;
            }

            $mergedPhones[$normalizedPhone] = [
                'VALUE' => $normalizedPhone,
                'VALUE_TYPE' => $index === 0 ? 'WORK' : 'OTHER',
            ];
        }

        foreach ($remotePhones as $remotePhone) {
            $normalizedPhone = $remotePhone['normalized'] ?? '';

            if ($normalizedPhone === '' || isset($mergedPhones[$normalizedPhone])) {
                continue;
            }

            $mergedPhones[$normalizedPhone] = [
                'VALUE' => $remotePhone['value'] ?: $normalizedPhone,
                'VALUE_TYPE' => $remotePhone['value_type'] ?: 'OTHER',
            ];
        }

        return array_values($mergedPhones);
    }
}

<?php

namespace Tests\Feature;

use App\Services\Bitrix24\Bitrix24ContactPayloadNormalizer;
use Tests\TestCase;

class Bitrix24ContactPayloadNormalizerTest extends TestCase
{
    public function test_it_normalizes_scalar_values(): void
    {
        $normalizer = new Bitrix24ContactPayloadNormalizer();

        $this->assertNull($normalizer->normalizeScalarValue(null));
        $this->assertNull($normalizer->normalizeScalarValue('   '));
        $this->assertSame('1', $normalizer->normalizeScalarValue(true));
        $this->assertSame('0', $normalizer->normalizeScalarValue(false));
        $this->assertSame('42', $normalizer->normalizeScalarValue(42));
        $this->assertSame('value', $normalizer->normalizeScalarValue(' value '));
    }

    public function test_it_normalizes_phone_payload_from_both_supported_shapes(): void
    {
        $normalizer = new Bitrix24ContactPayloadNormalizer();

        $normalized = $normalizer->normalizePhonePayload([
            [
                'VALUE' => ' +7 900 123-45-67 ',
                'VALUE_TYPE' => ' WORK ',
            ],
            [
                'value' => ' +7 901 555-00-11 ',
                'value_type' => ' MOBILE ',
            ],
            [
                'VALUE' => '   ',
                'VALUE_TYPE' => 'HOME',
            ],
            [
                'value' => ' +7 902 777-88-99 ',
            ],
        ]);

        $this->assertSame([
            [
                'VALUE' => '+7 900 123-45-67',
                'VALUE_TYPE' => 'WORK',
            ],
            [
                'VALUE' => '+7 901 555-00-11',
                'VALUE_TYPE' => 'MOBILE',
            ],
            [
                'VALUE' => '+7 902 777-88-99',
                'VALUE_TYPE' => 'OTHER',
            ],
        ], $normalized);
    }
}

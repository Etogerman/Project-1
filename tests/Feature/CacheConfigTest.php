<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use stdClass;
use Tests\TestCase;

class CacheConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_cache_object_deserialization_is_disabled(): void
    {
        $this->assertFalse(config('cache.serializable_classes'));
    }

    public function test_database_cache_preserves_arrays_without_rehydrating_objects(): void
    {
        $cache = Cache::store('database');
        $keyPrefix = 'cache-config-'.Str::uuid();
        $arrayKey = $keyPrefix.'-array-payload';
        $objectKey = $keyPrefix.'-object-payload';
        $arrayPayload = [
            'contact_id' => 123,
            'status' => 'ready',
        ];

        try {
            $cache->put($arrayKey, $arrayPayload, 60);
            $cache->put($objectKey, (object) ['status' => 'ready'], 60);

            $this->assertSame($arrayPayload, $cache->get($arrayKey));

            $cachedObject = $cache->get($objectKey);

            $this->assertInstanceOf(\__PHP_Incomplete_Class::class, $cachedObject);
            $this->assertNotInstanceOf(stdClass::class, $cachedObject);
        } finally {
            $cache->forget($arrayKey);
            $cache->forget($objectKey);
        }
    }
}

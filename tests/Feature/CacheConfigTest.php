<?php

namespace Tests\Feature;

use Tests\TestCase;

class CacheConfigTest extends TestCase
{
    public function test_cache_object_deserialization_is_disabled(): void
    {
        $this->assertFalse(config('cache.serializable_classes'));
    }
}

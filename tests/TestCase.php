<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('bots.telegram.local_api_media_download_enabled', false);

        config()->set('bots.max.pinned_media_ips', array_fill_keys([
            'cdn.max.example',
            'cdn.max.ru',
            'i.oneme.ru',
            'max.example',
            'maxvd126.okcdn.ru',
            'maxvd369.okcdn.ru',
            'pimg.mycdn.me',
            'st.mycdn.me',
        ], '93.184.216.34'));

    }
}

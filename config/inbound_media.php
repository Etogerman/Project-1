<?php

return [
    'temporary_directory' => env(
        'INBOUND_MEDIA_TEMPORARY_DIRECTORY',
        storage_path('app/inbound-media-tmp'),
    ),

    'manual_hard_limit_bytes' => (int) env(
        'INBOUND_MEDIA_MANUAL_HARD_LIMIT_BYTES',
        4 * 1024 * 1024 * 1024,
    ),

    'retention_days' => (int) env('INBOUND_MEDIA_RETENTION_DAYS', 90),

    'storage' => [
        'enforce' => (bool) env('INBOUND_MEDIA_STORAGE_QUOTA_ENFORCE', false),
        'global_limit_bytes' => (int) env(
            'INBOUND_MEDIA_STORAGE_GLOBAL_LIMIT_BYTES',
            50 * 1024 * 1024 * 1024,
        ),
        'channel_limit_bytes' => (int) env(
            'INBOUND_MEDIA_STORAGE_CHANNEL_LIMIT_BYTES',
            20 * 1024 * 1024 * 1024,
        ),
        'minimum_free_bytes' => (int) env(
            'INBOUND_MEDIA_STORAGE_MINIMUM_FREE_BYTES',
            10 * 1024 * 1024 * 1024,
        ),
        'minimum_free_percent' => (int) env('INBOUND_MEDIA_STORAGE_MINIMUM_FREE_PERCENT', 10),
    ],

    'traffic' => [
        'enforce' => (bool) env('INBOUND_MEDIA_TRAFFIC_QUOTA_ENFORCE', false),
        'channel_daily_limit_bytes' => env('INBOUND_MEDIA_TRAFFIC_CHANNEL_DAILY_LIMIT_BYTES'),
    ],

    'attempt_deadline_seconds' => (int) env(
        'INBOUND_MEDIA_ATTEMPT_DEADLINE_SECONDS',
        6 * 60 * 60,
    ),
    'lease_stale_seconds' => (int) env('INBOUND_MEDIA_LEASE_STALE_SECONDS', 120),
    'reservation_ttl_buffer_seconds' => (int) env(
        'INBOUND_MEDIA_RESERVATION_TTL_BUFFER_SECONDS',
        15 * 60,
    ),
    'orphan_grace_seconds' => (int) env(
        'INBOUND_MEDIA_ORPHAN_GRACE_SECONDS',
        (6 * 60 * 60) + (15 * 60),
    ),

    'admission' => [
        'channel_max_active' => (int) env('INBOUND_MEDIA_CHANNEL_MAX_ACTIVE', 2),
        'identity_max_active' => (int) env('INBOUND_MEDIA_IDENTITY_MAX_ACTIVE', 2),
        'global_max_active' => (int) env('INBOUND_MEDIA_GLOBAL_MAX_ACTIVE', 4),
        'manual_to_automatic_ratio' => (int) env('INBOUND_MEDIA_MANUAL_TO_AUTO_RATIO', 3),
        'retry_after_seconds' => (int) env('INBOUND_MEDIA_ADMISSION_RETRY_AFTER_SECONDS', 5),
    ],

    'cleanup' => [
        'unique_for_seconds' => (int) env(
            'INBOUND_MEDIA_CLEANUP_UNIQUE_FOR_SECONDS',
            (6 * 60 * 60) + (15 * 60),
        ),
        'retry_delays_seconds' => [60, 300, 900, 3600],
    ],

    'max_attempts' => 5,
    'retry_delays_seconds' => [60, 300, 900, 3600, 10800],
];

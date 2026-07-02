<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    'contact_avatars_disk' => env('CONTACT_AVATARS_DISK', 'contact_avatars'),

    'message_attachments_disk' => env('MESSAGE_ATTACHMENTS_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        'contact_avatars' => [
            'driver' => 's3',
            'key' => env('CONTACT_AVATARS_ACCESS_KEY_ID', env('AWS_ACCESS_KEY_ID')),
            'secret' => env('CONTACT_AVATARS_SECRET_ACCESS_KEY', env('AWS_SECRET_ACCESS_KEY')),
            'region' => env('CONTACT_AVATARS_DEFAULT_REGION', env('AWS_DEFAULT_REGION')),
            'bucket' => env('CONTACT_AVATARS_BUCKET', env('AWS_BUCKET')),
            'url' => env('CONTACT_AVATARS_URL', env('AWS_URL')),
            'endpoint' => env('CONTACT_AVATARS_ENDPOINT', env('AWS_ENDPOINT')),
            'use_path_style_endpoint' => env('CONTACT_AVATARS_USE_PATH_STYLE_ENDPOINT', env('AWS_USE_PATH_STYLE_ENDPOINT', false)),
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        'message_attachments' => [
            'driver' => env('MESSAGE_ATTACHMENTS_DRIVER', 's3'),
            'key' => env('MESSAGE_ATTACHMENTS_ACCESS_KEY_ID', env('AWS_ACCESS_KEY_ID')),
            'secret' => env('MESSAGE_ATTACHMENTS_SECRET_ACCESS_KEY', env('AWS_SECRET_ACCESS_KEY')),
            'region' => env('MESSAGE_ATTACHMENTS_DEFAULT_REGION', env('AWS_DEFAULT_REGION')),
            'bucket' => env('MESSAGE_ATTACHMENTS_BUCKET', env('AWS_BUCKET')),
            'url' => env('MESSAGE_ATTACHMENTS_URL', env('AWS_URL')),
            'endpoint' => env('MESSAGE_ATTACHMENTS_ENDPOINT', env('AWS_ENDPOINT')),
            'use_path_style_endpoint' => env('MESSAGE_ATTACHMENTS_USE_PATH_STYLE_ENDPOINT', env('AWS_USE_PATH_STYLE_ENDPOINT', false)),
            'visibility' => 'private',
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];

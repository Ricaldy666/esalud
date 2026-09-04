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
            // Sin esto, Flysystem usa su default de fabrica para directorios
            // "privados" (0700, propietario unicamente -- ver
            // League\Flysystem\UnixVisibility\PortableVisibilityConverter).
            // Eso dejo storage/app/private/certificacion/cell-data ilegible
            // para PHP-FPM (usuario/grupo www-data, distinto del propietario
            // que creo el directorio) -- causa raiz de una incidencia real en
            // produccion 2026-09-04. Minimo privilegio: rw/rwx para
            // propietario y grupo, nada para "otros" (storage/app/private
            // contiene datos privados).
            'permissions' => [
                'file' => ['public' => 0660, 'private' => 0660],
                'dir' => ['public' => 0770, 'private' => 0770],
            ],
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        'rem-uploads' => [
            'driver' => 'local',
            'root' => storage_path('app/rem-uploads'),
            'throw' => false,
        ],

        'rem-discovery' => [
            'driver' => 'local',
            'root' => storage_path('app/rem-discovery'),
            'throw' => false,
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

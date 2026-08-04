<?php

return [

    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        'http://192.168.1.144:5173',

        // Producción
        'http://atenea.cormudesi.cl',
        'https://atenea.cormudesi.cl',
    ],

    // Permite cualquier IP de la red 192.168.1.x (útil para pruebas)
    'allowed_origins_patterns' => [
        '#^http://192\.168\.1\.\d+:5173$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];

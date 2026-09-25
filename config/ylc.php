<?php

return [
    'discovery' => [
        [
            'path' => path('Live'),
            'namespace' => 'App\\Live',
            'prefix' => null,
        ],
    ],

    'uploads' => [
        'maxSize' => 10 * 1024 * 1024,
        'types' => ['image/jpeg', 'image/png', 'application/pdf'],
    ],
];

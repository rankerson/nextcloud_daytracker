<?php

declare(strict_types=1);

return [
    'routes' => [
        [
            'name' => 'page#index',
            'url' => '/',
            'verb' => 'GET',
        ],
        [
            'name' => 'page#catalog',
            'url' => '/api/catalog',
            'verb' => 'GET',
        ],
        [
            'name' => 'page#saveCatalog',
            'url' => '/api/catalog',
            'verb' => 'POST',
        ],
        [
            'name' => 'page#day',
            'url' => '/api/day/{date}',
            'verb' => 'GET',
            'requirements' => [
                'date' => '\\d{4}-\\d{2}-\\d{2}',
            ],
        ],
        [
            'name' => 'page#saveDay',
            'url' => '/api/day/{date}',
            'verb' => 'POST',
            'requirements' => [
                'date' => '\\d{4}-\\d{2}-\\d{2}',
            ],
        ],
        [
            'name' => 'page#exportCsv',
            'url' => '/export.csv',
            'verb' => 'GET',
        ],
    ],
];

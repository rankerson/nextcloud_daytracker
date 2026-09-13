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
            'name' => 'page#getCatalog',
            'url' => '/api/catalog',
            'verb' => 'GET',
        ],
        [
            'name' => 'page#saveCatalog',
            'url' => '/api/catalog',
            'verb' => 'POST',
        ],
        [
            'name' => 'page#getDay',
            'url' => '/api/day/{date}',
            'verb' => 'GET',
        ],
        [
            'name' => 'page#saveDay',
            'url' => '/api/day/{date}',
            'verb' => 'POST',
        ],
        [
            'name' => 'page#exportCsv',
            'url' => '/export.csv',
            'verb' => 'GET',
        ],
    ],
];

<?php

return [
    'defaults' => [
        'ttl' => 3600,
        'prefix' => 'cachelet',
    ],
    'observability' => [
        'events' => [
            'enabled' => false,
        ],
    ],
    'observe' => [
        'auto_register' => true,
    ],
    'stale' => [
        'lock_suffix' => ':refresh',
        'lock_ttl' => 30,
        'grace_ttl' => 300,
        'refresh' => 'sync',
    ],
    'locks' => [
        'fill_suffix' => ':fill',
        'fill_ttl' => 30,
        'fill_wait' => 5,
    ],
    'serialization' => [
        'exclude_dates' => true,
        'default_excludes' => [],
        'default_only' => [],
    ],
    'query' => [
        'default_prefix' => 'query',
        'pagination_keys' => ['cursor', 'page', 'per_page'],
    ],
    'request' => [
        'default_prefix' => 'request',
        'cache_methods' => ['GET', 'HEAD'],
        'cache_statuses' => [200],
        'middleware_alias' => 'cachelet',
        'vary' => [
            'query' => true,
            'headers' => [],
            'auth' => false,
            'locale' => false,
        ],
    ],
];

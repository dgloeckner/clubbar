<?php

/**
 * Ruderbar Configuration
 *
 * Copy this file to config.php and fill in your database credentials.
 * Or use the web installer: visit /install.php in your browser.
 */
return [
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'ruderbar',
        'user' => '',
        'pass' => '',
    ],
    'app' => [
        'env' => 'production',
        'debug' => false,
        'url' => 'https://example.com',
    ],
    'session' => [
        'max_age' => 7200,
        'regeneration_interval' => 900,
    ],
    'api_token' => [
        'ttl_days' => 90,
    ],
];

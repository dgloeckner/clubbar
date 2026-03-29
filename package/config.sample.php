<?php

/**
 * Club Bar Configuration
 *
 * Copy this file to config.php and fill in your database credentials.
 * Or use the web installer: visit /install.php in your browser.
 */
return [
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'clubbar',
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
    'security' => [
        // 64-char hex string (32 bytes). Generated automatically by install.php.
        // To regenerate manually: openssl rand -hex 32
        'totp_encryption_key' => '',
    ],
    'llm' => [
        // Optional: enables AI-powered mandate document extraction.
        // Leave provider empty (or omit this section) to disable extraction silently.
        // Supported providers: 'anthropic', 'openai'
        'provider' => '',
        'api_key' => '',
        'model' => '',  // defaults: claude-haiku-4-5-20251001 (anthropic) / gpt-4o-mini (openai)
        'thinking_budget' => 0,
    ],
];

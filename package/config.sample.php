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
        // Where PHP writes session files. Left unset, the app uses
        // backend/storage/sessions rather than the host's shared session
        // directory, where another account on the machine may be able to read
        // them. Point this at a directory outside the document root if your
        // hosting offers one — it must be writable by the web server.
        // 'save_path' => '/home/user/clubbar-data/sessions',
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

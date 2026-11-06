<?php
declare(strict_types=1);

/**
 * Configuration comes from environment variables only. No credentials live in code,
 * and there is deliberately NO fallback to the MySQL root account (issue 8).
 */
return [
    'db_host' => getenv('LAB_DB_HOST') ?: '127.0.0.1',
    'db_name' => getenv('LAB_DB_NAME') ?: 'notes_lab',
    'db_user' => getenv('LAB_DB_USER') ?: '',
    'db_pass' => getenv('LAB_DB_PASSWORD') ?: '',

    // Origins allowed to make state-changing API calls (issue 11).
    // Same-origin only by default; the API sends no CORS headers at all.
    'allowed_origins' => array_filter(array_map(
        'trim',
        explode(',', getenv('LAB_ALLOWED_ORIGINS') ?: 'http://127.0.0.1:8000,http://localhost:8000')
    )),

    'session_idle_seconds' => 1800,
    'min_password_length'  => 10,
];

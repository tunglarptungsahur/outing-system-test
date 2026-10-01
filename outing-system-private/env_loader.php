<?php
// Loads a .env file (if one exists) into the environment, so getenv()
// picks up DB_HOST/DB_NAME/DB_USER/DB_PASS without needing Apache/nginx
// config changes. Safe to call even if .env doesn't exist (e.g. on a
// server where you set real env vars a different way) -- and it never
// overwrites a variable that's already set, so real server config
// always wins over a leftover .env file.

declare(strict_types=1);

function load_env(string $path): void
{
    if (!is_readable($path)) {
        return;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
        $key   = trim($key);
        $value = trim($value, " \t\n\r\0\x0B\"'");

        if ($key === '' || getenv($key) !== false) {
            continue; // don't overwrite a variable the server already set
        }

        putenv("{$key}={$value}");
    }
}

<?php
// Single source of truth for where the private library lives.
//
// Every page used to hardcode PRIVATE_LIB . '/...'
// directly in its own require_once lines -- 54 copies of the same
// absolute, Windows-only, this-one-machine-only path across 20 files.
// That breaks the moment this runs anywhere else: a different drive
// letter, a Linux server, a teammate's laptop with XAMPP installed
// somewhere else, or just moving folders around.
//
// Now there's exactly one place this path is decided. Every page
// requires this file first, then reaches the private library through
// the PRIVATE_LIB constant it defines here -- so deploying to a
// different machine or server is a one-line change in this file
// instead of a find-and-replace across the whole webroot.
//
// Default matches the current setup exactly, so nothing changes
// unless you actually need it to. To point at a different location
// (a different drive, a Linux path, another dev machine), either:
//   - edit the fallback path below, or
//   - set an OUTING_PRIVATE_PATH environment variable (e.g. in your
//     Apache vhost config, or a .env file next to this one) -- that
//     always wins over the fallback, no code change needed.

declare(strict_types=1);

if (!defined('PRIVATE_LIB')) {
    $privatePath = getenv('OUTING_PRIVATE_PATH') ?: 'C:/xampp/outing-system-private';
    define('PRIVATE_LIB', rtrim($privatePath, '/\\'));
}

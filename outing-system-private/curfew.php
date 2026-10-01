<?php
// Curfew window -- now a DB-backed setting (app_setting, via
// settings.php) instead of a hardcoded constant, so it and the
// special-outing tolerance can be tuned from the admin settings page,
// including for testing. DEFAULT_CURFEW_START/END below are only the
// seed values a fresh install starts with (see SETTING_DEFAULTS in
// settings.php, which agrees with these) -- the live value always
// comes from curfew_start()/curfew_end().

declare(strict_types=1);
require_once __DIR__ . '/settings.php';

const DEFAULT_CURFEW_START = '23:00:00'; // 11:00 PM
const DEFAULT_CURFEW_END   = '06:00:00'; // 6:00 AM, next day

function curfew_start(): string
{
    return get_setting('curfew_start');
}

function curfew_end(): string
{
    return get_setting('curfew_end');
}

/**
 * True if the given time (default: right now) falls inside the
 * curfew window. Handles the midnight wraparound correctly --
 * 23:30 and 02:00 both count as "in curfew" with the defaults above,
 * even though 02:00 is numerically less than 23:00.
 */
function is_within_curfew(?string $atTime = null): bool
{
    $time  = $atTime !== null ? date('H:i:s', strtotime($atTime)) : date('H:i:s');
    $start = curfew_start();
    $end   = curfew_end();

    if ($start <= $end) {
        // Non-wrapping window, e.g. 01:00-05:00.
        return $time >= $start && $time < $end;
    }

    // Wrapping window, e.g. 23:00-06:00: "in curfew" if at/after start
    // OR before end.
    return $time >= $start || $time < $end;
}

/** Human-readable window, for messages shown to guards/students. */
function curfew_window_label(): string
{
    return date('g:i A', strtotime(curfew_start())) . ' - ' . date('g:i A', strtotime(curfew_end()));
}

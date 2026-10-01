<?php
// Small DB-backed key/value settings store. Lets admin tune runtime
// behaviour -- the curfew window and the special-outing grace period --
// without touching code, including for testing. These used to be
// hardcoded constants in curfew.php; now curfew.php just reads them
// from here (see DEFAULT_CURFEW_START/END there for the seed values a
// fresh install starts with).
//
// Cached per-request (a static array) since get_setting() is called on
// nearly every page load and the values rarely change; set_setting()
// invalidates the cache so a save takes effect immediately, without
// needing a redirect first.

declare(strict_types=1);
require_once __DIR__ . '/db.php';

/** Fallback used only if a key is missing from app_setting entirely. */
const SETTING_DEFAULTS = [
    'curfew_start'              => '23:00:00',
    'curfew_end'                => '06:00:00',
    'special_tolerance_minutes' => '15',
    'qr_refresh_seconds'        => '30',
    'allow_manual_gate_entry'   => '1',
];

/** Everything the settings page knows how to show/edit. */
const SETTING_META = [
    'curfew_start' => [
        'label' => 'Curfew start',
        'type'  => 'time',
        'help'  => 'Standard-track check-ins after this time count as during curfew.',
    ],
    'curfew_end' => [
        'label' => 'Curfew end',
        'type'  => 'time',
        'help'  => 'Curfew ends at this time the next morning.',
    ],
    'special_tolerance_minutes' => [
        'label' => 'Special outing tolerance (minutes)',
        'type'  => 'number',
        'help'  => 'Grace period after a special request\'s agreed/expected time before it counts as a violation or gets flagged overdue.',
        'min'   => 0,
    ],
    'qr_refresh_seconds' => [
        'label' => 'QR refresh interval (seconds)',
        'type'  => 'number',
        'help'  => 'How often a student\'s QR code changes (30-60). Shorter is harder to share by screenshot.',
        'min'   => 30,
        'max'   => 60,
    ],
    'allow_manual_gate_entry' => [
        'label' => 'Allow manual student-number entry at the gate',
        'type'  => 'bool',
        'help'  => 'If No, guards can only process students by scanning their live QR code (no typing a number).',
    ],
];

/** Loads every row from app_setting once per request (or forces a reload after a save). */
function _settings_cache(bool $reset = false): array
{
    static $cache = null;
    if ($reset || $cache === null) {
        $cache = [];
        $stmt = get_db()->query('SELECT setting_key, setting_value FROM app_setting');
        foreach ($stmt->fetchAll() as $row) {
            $cache[$row['setting_key']] = $row['setting_value'];
        }
    }
    return $cache;
}

function get_setting(string $key): string
{
    $cache = _settings_cache();
    return $cache[$key] ?? (SETTING_DEFAULTS[$key] ?? '');
}

/** Grace period (minutes) before a special-outing request counts as a violation / gets flagged overdue. */
function special_tolerance_minutes(): int
{
    return max(0, (int) get_setting('special_tolerance_minutes'));
}

/** Current value of every known setting, with its label/type/help -- for the settings page. */
function get_all_settings(): array
{
    $out = [];
    foreach (SETTING_META as $key => $meta) {
        $out[$key] = ['key' => $key, 'value' => get_setting($key)] + $meta;
    }
    return $out;
}

/**
 * Upserts one setting. $staffId is who changed it (recorded in
 * updated_by/updated_at) -- enough of an audit trail for something
 * this low-stakes without a separate settings-history table.
 */
function set_setting(string $key, string $value, string $staffId): void
{
    if (!array_key_exists($key, SETTING_META)) {
        throw new InvalidArgumentException('Unknown setting.');
    }

    $type = SETTING_META[$key]['type'];
    if ($type === 'time') {
        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $value)) {
            throw new InvalidArgumentException('Please enter a valid time.');
        }
        if (strlen($value) === 5) {
            $value .= ':00';
        }
    } elseif ($type === 'number') {
        if (!preg_match('/^\d+$/', $value)) {
            throw new InvalidArgumentException('Please enter a whole number.');
        }
        $meta = SETTING_META[$key];
        if ((isset($meta['min']) && (int) $value < $meta['min']) || (isset($meta['max']) && (int) $value > $meta['max'])) {
            throw new InvalidArgumentException(
                'Value must be between ' . ($meta['min'] ?? 0) . (isset($meta['max']) ? ' and ' . $meta['max'] : ' or more') . '.'
            );
        }
    } elseif ($type === 'bool') {
        if ($value !== '0' && $value !== '1') {
            throw new InvalidArgumentException('Choose Yes or No.');
        }
    }

    $stmt = get_db()->prepare(
        'INSERT INTO app_setting (setting_key, setting_value, updated_by)
         VALUES (:key, :value, :staff_id)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)'
    );
    $stmt->execute(['key' => $key, 'value' => $value, 'staff_id' => $staffId]);

    _settings_cache(true);
}

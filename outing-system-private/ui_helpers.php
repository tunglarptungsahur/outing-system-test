<?php
// Small helpers shared by every page. Kept separate from auth.php since
// these are about rendering/UX, not access control.

declare(strict_types=1);
require_once __DIR__ . '/auth.php'; // for start_secure_session()

/** Shorthand for the output-escaping every dynamic value needs. */
function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/** Store a one-time message to show after a redirect. */
function flash_set(string $type, string $message): void
{
    start_secure_session();
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

/** Reads and clears the flash message, if any. */
function flash_get(): ?array
{
    start_secure_session();
    if (empty($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

/** Renders the flash message as HTML, or nothing if there isn't one. */
function flash_render(): string
{
    $flash = flash_get();
    if (!$flash) {
        return '';
    }
    $class = $flash['type'] === 'error' ? 'm-alert m-alert--danger' : 'm-alert m-alert--success';
    return '<div class="' . $class . '" role="alert">' . h($flash['message']) . '</div>';
}

/**
 * Converts a <input type="datetime-local"> value ("2026-09-10T14:30")
 * into MySQL DATETIME format ("2026-09-10 14:30:00").
 */
function normalize_datetime_local(string $value): string
{
    $value = str_replace('T', ' ', trim($value));
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value)) {
        $value .= ':00';
    }
    return $value;
}

/** Reverse of normalize_datetime_local(), for pre-filling edit forms. */
function to_datetime_local(string $mysqlDatetime): string
{
    $ts = strtotime($mysqlDatetime);
    return $ts === false ? '' : date('Y-m-d\TH:i', $ts);
}

/** First letter of up to the first two words of a name, e.g. "Staff Test One" -> "ST". */
function initials(string $name): string
{
    $words = preg_split('/\s+/', trim($name));
    $letters = array_map(fn($w) => mb_strtoupper(mb_substr($w, 0, 1)), array_slice($words, 0, 2));
    return implode('', $letters) ?: '?';
}

/**
 * Renders the avatar-trigger + gradient profile card used by both
 * menuheader.php (staff) and student_header.php (student). $links is
 * a list of ['href' => ..., 'label' => ...] shown below the divider,
 * with logout always appended last and styled as the pill button.
 */
function profile_dropdown(string $name, string $idLabel, string $roleLabel, ?string $email, array $links): string
{
    $ariaLabel = 'Account menu for ' . $name . ', ' . $roleLabel;
    $out  = '<details class="user-menu">';
    $out .= '<summary class="user-menu__avatar" aria-label="' . h($ariaLabel) . '">' . h(initials($name)) . '</summary>';
    $out .= '<div class="user-menu__dropdown">';
    $out .= '<div class="user-menu__card">';
    $out .= '<div class="user-menu__avatar user-menu__avatar--lg">' . h(initials($name)) . '</div>';
    $out .= '<div class="user-menu__name">' . h($name) . ' <span class="user-menu__id">(' . h($idLabel) . ')</span></div>';
    if ($email) {
        $out .= '<div class="user-menu__email">' . h($email) . '</div>';
    }
    $out .= '</div>';
    $out .= '<div class="user-menu__actions">';
    foreach ($links as $link) {
        $out .= '<a href="' . h($link['href']) . '">' . h($link['label']) . '</a>';
    }
    $out .= '<a href="logout.php" class="user-menu__logout">Logout</a>';
    $out .= '</div></div></details>';
    return $out;
}

/** Human-friendly status labels for display. */
function status_label(string $status): string
{
    $labels = [
        'pending'      => 'Pending',
        'approved'     => 'Approved',
        'rejected'     => 'Rejected',
        'checked_out'  => 'Checked out',
        'checked_in'   => 'Checked in',
        'overdue'      => 'Overdue',
        'cancelled'    => 'Cancelled',
    ];
    return $labels[$status] ?? ucfirst($status);
}

/**
 * Reads date_from / date_to from $_GET, validated as YYYY-MM-DD.
 * An invalid or missing value comes back as null (meaning: no bound),
 * rather than ever being trusted into a query unchecked.
 */
function parse_date_range(): array
{
    $isValidDate = fn(string $v) => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false;
    $from = $_GET['date_from'] ?? '';
    $to   = $_GET['date_to'] ?? '';
    return [
        'from' => $isValidDate($from) ? $from : null,
        'to'   => $isValidDate($to) ? $to : null,
    ];
}

/**
 * Builds an "AND column BETWEEN ..." SQL fragment for an optional date
 * range and appends the matching bind values to $params by reference.
 * $to is a calendar date, so it's extended to the end of that day --
 * otherwise "2026-09-17" would exclude everything that happened later
 * that same day. Returns '' (no-op) when both bounds are null.
 */
function date_range_sql(string $column, ?string $from, ?string $to, array &$params): string
{
    $sql = '';
    if ($from !== null) {
        $sql .= " AND {$column} >= :date_from";
        $params['date_from'] = $from . ' 00:00:00';
    }
    if ($to !== null) {
        $sql .= " AND {$column} <= :date_to";
        $params['date_to'] = $to . ' 23:59:59';
    }
    return $sql;
}

/**
 * A print button styled to match the UI. PDF export goes through the
 * browser's own print-to-PDF (window.print() + a @media print
 * stylesheet that hides the header/sidebar/filter form) rather than a
 * server-side PDF library -- no extra dependency to install on the
 * XAMPP box, and it reuses styling already in app.css.
 */
function export_pdf_button(): string
{
    return '<button type="button" class="no-print btn-export" onclick="window.print()">Export to PDF</button>';
}

/**
 * Renders the "From / To / Filter / Clear" date range form shared by
 * every report and history page. $hidden carries any other filter
 * (status, late-only, etc.) forward as hidden fields so switching the
 * date range doesn't silently reset it.
 */
function date_filter_form(string $action, ?string $from, ?string $to, array $hidden = []): string
{
    $out  = '<form method="get" action="' . h($action) . '" class="date-filter">';
    foreach ($hidden as $name => $value) {
        if ($value !== null && $value !== '') {
            $out .= '<input type="hidden" name="' . h($name) . '" value="' . h($value) . '">';
        }
    }
    $out .= '<label>From <input type="date" name="date_from" value="' . h($from ?? '') . '"></label>';
    $out .= '<label>To <input type="date" name="date_to" value="' . h($to ?? '') . '"></label>';
    $out .= '<button type="submit">Filter</button>';
    if ($from !== null || $to !== null) {
        $out .= ' <a href="' . h($action) . '">Clear</a>';
    }
    $out .= '</form>';
    return $out;
}

/** MySQL DATETIME -> "21 Sep 2026, 3:05 PM". Empty string for null/invalid. */
function fmt_dt(?string $mysqlDatetime): string
{
    if ($mysqlDatetime === null || $mysqlDatetime === '') {
        return '';
    }
    $ts = strtotime($mysqlDatetime);
    return $ts === false ? '' : date('j M Y, g:i A', $ts);
}

/** track key -> label used on the violation and history pages. */
function track_label(string $track): string
{
    $labels = [
        'special_checkout' => 'Special (leave permission)',
        'special_checkin'  => 'Special (arrival permission)',
        'special'          => 'Special outing', // legacy rows from before the split
        'standard'         => 'Standard outing',
    ];
    return $labels[$track] ?? ucfirst($track);
}

/** "checkout" / "checkin" -> label used on request forms and tables. */
function request_type_label(string $type): string
{
    return $type === 'checkin' ? 'Arriving during curfew' : 'Leaving during curfew';
}

/**
 * The "destination" column doubles as "where did you come from" for a
 * checkin-type request (e.g. delayed bus/flight arriving after curfew) --
 * it's still stored as `destination`, just asked and labeled differently
 * depending on which type of request this is.
 */
function destination_field_label(string $type): string
{
    return $type === 'checkin' ? 'Coming from' : 'Destination';
}

/**
 * Tab bar (link-based, server-side filtering).
 * $items = ['key' => ['Label', 'href'], ...]; $active = key of the selected tab.
 */
function tab_nav(array $items, string $active): string
{
    $html = '<nav class="tabs no-print" role="tablist">';
    foreach ($items as $key => [$label, $href]) {
        $on = ((string)$key === $active);
        $html .= '<a role="tab" aria-selected="' . ($on ? 'true' : 'false') . '" class="tab' . ($on ? ' active' : '')
               . '" href="' . h($href) . '">' . h($label) . '</a>';
    }
    return $html . '</nav>';
}

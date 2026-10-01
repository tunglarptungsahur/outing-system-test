<?php
// Shared header + side panel for student-facing pages. Same layout as
// staff (menuheader.php + mainmenu.php): all navigation lives in the
// side panel (student_menu.php); the profile dropdown only has
// My profile + Logout.
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once PRIVATE_LIB . '/ui_helpers.php';
?>
<link rel="stylesheet" href="assets/app.css?v=<?= filemtime(__DIR__ . '/assets/app.css') ?>">
<header id="m_header" class="m-grid__item m-header" m-minimize-offset="200" m-minimize-mobile-offset="200">
    <div class="m-container m-container--fluid m-container--full-height">
        <div class="m-stack m-stack--ver m-stack--desktop">
            <div class="m-stack__item m-brand m-brand--skin-dark">
                <button type="button" id="navToggle" class="nav-toggle" aria-label="Toggle menu" aria-controls="m_aside_left" aria-expanded="false">
                    <span></span><span></span><span></span>
                </button>
                <div class="m-stack m-stack--ver m-stack--general">
                    <div class="m-stack__item m-stack__item--middle m-brand__logo">
                        <a href="student_dashboard.php" class="m-brand__logo-wrapper">
                            <img alt="Outing System" src="images/logo_dark.png">
                        </a>
                    </div>
                </div>
            </div>
            <div class="m-stack__item m-stack__item--middle m-stack__item--right header-right">
                <?php require 'clock_widget.php'; ?>
                <div class="notif-bell-wrap" style="position:relative;">
                    <button id="notifBellBtn" class="notif-bell" type="button" aria-label="Notifications"
                            style="position:relative;background:none;border:none;cursor:pointer;color:#6f727d;font-size:18px;line-height:1;padding:6px;">
                        <span aria-hidden="true" style="display:inline-flex;"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg></span>
                        <span id="notifBadge" style="display:none;position:absolute;top:0;right:0;min-width:16px;height:16px;padding:0 4px;border-radius:8px;background:#c0392b;color:#ffffff;font-size:10.5px;font-weight:700;line-height:16px;text-align:center;">0</span>
                    </button>
                    <div id="notifDropdown" style="display:none;position:absolute;right:0;top:calc(100% + 10px);width:320px;max-width:calc(100vw - 24px);max-height:420px;overflow-y:auto;background:#ffffff;border-radius:10px;box-shadow:0 10px 28px rgba(0,0,0,0.22);z-index:200;color:#24152f;">
                        <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 14px;border-bottom:1px solid #e2dbe9;font-weight:600;font-size:13.5px;background:#ffffff;color:#24152f;">
                            <span>Notifications</span>
                            <button id="notifMarkAllRead" type="button" style="background:none;border:none;color:#5b1a8b;font-size:12px;cursor:pointer;padding:0;">Mark all read</button>
                        </div>
                        <div id="notifList" style="padding:4px 0;">
                            <p style="padding:20px 14px;text-align:center;color:#7a6d86;font-size:13px;margin:0;">Loading&hellip;</p>
                        </div>
                    </div>
                </div>
                <?= profile_dropdown(
                    $_SESSION['name'] ?? '',
                    $_SESSION['std_no'] ?? '',
                    'student',
                    $_SESSION['email'] ?? null,
                    [['href' => 'profile.php', 'label' => 'My profile']]
                ) ?>
            </div>
        </div>
    </div>
</header>
<?php require 'student_menu.php'; ?>
<script>
(function () {
    var CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
    var bellBtn   = document.getElementById('notifBellBtn');
    var dropdown  = document.getElementById('notifDropdown');
    var badge     = document.getElementById('notifBadge');
    var list      = document.getElementById('notifList');
    var markAll   = document.getElementById('notifMarkAllRead');

    function escapeHtml(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function refreshUnreadCount() {
        fetch('get_unread_count.php')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.count > 0) {
                    badge.textContent = data.count;
                    badge.style.display = 'inline-block';
                } else {
                    badge.style.display = 'none';
                }
            })
            .catch(function () {});
    }

    function loadNotifications() {
        fetch('get_notifications.php')
            .then(function (r) { return r.json(); })
            .then(function (items) {
                if (!items.length) {
                    list.innerHTML = '<p style="padding:20px 14px;text-align:center;color:#7a6d86;font-size:13px;margin:0;">No notifications</p>';
                    return;
                }
                list.innerHTML = items.map(function (n) {
                    var rowBg = n.is_read == 0 ? '#f3ecf9' : '#ffffff';
                    return '<div class="notif-item" data-id="' + n.id + '" style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;padding:10px 14px;border-bottom:1px solid #f2f2f2;font-size:13px;background:' + rowBg + ';">'
                        + '<div style="display:flex;flex-direction:column;gap:2px;">'
                        + '<span style="color:#24152f;">' + escapeHtml(n.message) + '</span>'
                        + '<span style="color:#7a6d86;font-size:11.5px;">' + escapeHtml(n.created_at_label) + '</span>'
                        + '</div>'
                        + '<span style="display:flex;gap:6px;flex-shrink:0;">'
                        + (n.is_read == 0 ? '<button class="notif-read-btn" title="Mark read" style="background:none;border:none;cursor:pointer;color:#7a6d86;font-size:12px;">&#10003;</button>' : '')
                        + '<button class="notif-delete-btn" title="Delete" style="background:none;border:none;cursor:pointer;color:#7a6d86;font-size:12px;">&#10005;</button>'
                        + '</span>'
                        + '</div>';
                }).join('');
            })
            .catch(function () {
                list.innerHTML = '<p style="padding:20px 14px;text-align:center;color:#7a6d86;font-size:13px;margin:0;">Could not load notifications</p>';
            });
    }

    function notifAction(action, id) {
        return fetch('notification_action.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=' + encodeURIComponent(action)
                + '&id=' + encodeURIComponent(id || '')
                + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
        });
    }

    bellBtn.addEventListener('click', function () {
        var isOpen = dropdown.style.display === 'block';
        dropdown.style.display = isOpen ? 'none' : 'block';
        if (!isOpen) loadNotifications();
    });

    markAll.addEventListener('click', function () {
        notifAction('mark_all_read').then(function () {
            loadNotifications();
            refreshUnreadCount();
        });
    });

    list.addEventListener('click', function (e) {
        var item = e.target.closest('.notif-item');
        if (!item) return;
        var id = item.getAttribute('data-id');
        if (e.target.classList.contains('notif-read-btn')) {
            notifAction('mark_read', id).then(function () {
                loadNotifications();
                refreshUnreadCount();
            });
        }
        if (e.target.classList.contains('notif-delete-btn')) {
            notifAction('delete', id).then(function () {
                loadNotifications();
                refreshUnreadCount();
            });
        }
    });

    document.addEventListener('click', function (e) {
        if (!bellBtn.contains(e.target) && !dropdown.contains(e.target)) {
            dropdown.style.display = 'none';
        }
    });

    refreshUnreadCount();
    setInterval(refreshUnreadCount, 45000); // AJAX poll every 45s
})();
</script>
<script>
(function () {
    var btn = document.getElementById('navToggle');
    function setNav(open) {
        document.body.classList.toggle('nav-open', open);
        if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    if (btn) {
        btn.addEventListener('click', function () {
            setNav(!document.body.classList.contains('nav-open'));
        });
    }
    // Tap the dimmed area (body itself) or press Esc to close the mobile menu.
    document.addEventListener('click', function (e) {
        if (e.target === document.body) setNav(false);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') setNav(false);
    });
})();
document.addEventListener('click', function (e) {
    document.querySelectorAll('.user-menu[open]').forEach(function (menu) {
        if (!menu.contains(e.target)) {
            menu.removeAttribute('open');
        }
    });
});
</script>

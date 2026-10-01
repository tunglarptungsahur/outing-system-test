# Outing System

PHP + MySQL web app for monitoring student outings through a campus gate.

**Two tracks**

- **Standard track** – no approval. A guard scans the student's QR (or types the student number) to check out and check in.
- **Special / curfew track** – during curfew hours (default 23:00–06:00) a student submits a request, a warden approves or rejects it, and a guard executes it at the gate.

**Other features:** rotating (TOTP) QR codes, group outings with a Master QR, student notifications (bell), overdue/violation reports, CSV student import, role-based access (student, guard, warden, admin).

---

## Repository layout

```
database/
  schema.sql              Full database (all tables + default settings). Start here.
  seed.sql                Optional demo data (staff, students, sample outings)
  migrations/             Only for upgrading an OLD database. Not needed for a fresh install.
outing-system-test/       Web root (put under Apache's htdocs)
outing-system-private/    Application library, kept OUTSIDE the web root
  storage/photos/         Student photos (<std_no>.jpg), not committed
  .env.example            Database / mail settings template
```

`outing-system-private/` holds the database code, auth and business logic. The web pages load it through `bootstrap.php`, so it must **not** be inside the public web root.

## Requirements

- PHP 8.0+ with `pdo_mysql`, `mbstring`, `gd`
- MySQL 5.7+ or MariaDB 10.x
- Apache (XAMPP works)
- HTTPS if you want phone camera scanning on a real network (browsers block the camera on plain HTTP; `localhost` is exempt)

## Setup (XAMPP on Windows)

1. **Copy the folders**
   - `outing-system-test/` → `C:\xampp\htdocs\outing-system-test\`
   - `outing-system-private/` → `C:\xampp\outing-system-private\`

   `bootstrap.php` expects the private folder at `C:/xampp/outing-system-private`. For any other location see *Other layouts* below.

2. **Start Apache and MySQL** from the XAMPP control panel.

3. **Create the database**

   > `schema.sql` **drops and recreates** the `outing_system` database. Never run it on data you want to keep.

   phpMyAdmin: open the **Import** tab and import `database/schema.sql`, then `database/seed.sql` (demo data, optional).

   Or from a terminal:
   ```
   mysql -u root < database/schema.sql
   mysql -u root < database/seed.sql
   ```

4. **Configure the connection (optional on default XAMPP)**

   With no config the app connects as `root` with an empty password to `outing_system` on `127.0.0.1`, which matches default XAMPP. To change it:
   ```
   copy outing-system-private\.env.example outing-system-private\.env
   ```
   Then edit `.env` (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `MAIL_FROM`). `.env` is git-ignored. Variables set by the server itself take priority over `.env`.

5. **Open the app:** `http://localhost/outing-system-test/`

### Other layouts (Linux, macOS, different folder)

Tell the web pages where the private folder is, using either:
- an environment variable, e.g. in your Apache vhost: `SetEnv OUTING_PRIVATE_PATH /var/www/outing-system-private`, or
- editing the fallback path in `outing-system-test/bootstrap.php`.

Make sure the web server user can read the private folder and **write** to `outing-system-private/storage/photos/`.

## Demo accounts (only if you imported `seed.sql`)

| Role | Login page | ID | Password |
|---|---|---|---|
| Admin (view and reports only) | `login_staff.php` | `ADM001` | `Staff@123` |
| Warden (approves requests) | `login_staff.php` | `STF001`, `STF002` | `Staff@123` |
| Guard (gate) | `login_staff.php` | `GRD001`, `GRD002` | `Staff@123` |
| Student | `login_student.php` | `2026000001` – `2026000015` | `Student@123` |

`GRD003` is a deactivated guard and `2026000002` is forced to change password at first login. Both are there to test those cases.

**Delete the demo accounts or change their passwords before any real use.**

## Trying it out

The seed data covers each state. Useful students to look up:

| Student | Scenario |
|---|---|
| `2026000003` | Special checkout request pending (log in as a warden to approve/reject) |
| `2026000005` / `06` | Special checkout approved, ready to scan at the gate (log in as a guard) |
| `2026000001` | Approved arrival never showed up, so it is **overdue** |
| `2026000013` | Out on a standard outing, leader of an active group |
| `2026000015` | Late standard return, has a pending group invite |

The full scenario list is in the header comment of `database/seed.sql`.

## Scheduled jobs (required for alerts and overdue flagging)

Run both PHP scripts on a schedule. Windows Task Scheduler example:

| Script | Interval | Purpose |
|---|---|---|
| `outing-system-private\check_overdue.php` | every 5–10 min | flags overdue students, sends overdue emails |
| `outing-system-private\check_time_alerts.php` | every 1–2 min | 60/30/10-minute curfew reminders |

- Program: `C:\xampp\php\php.exe`
- Arguments: full path of the script
- Start in: `C:\xampp\outing-system-private`

Linux cron:
```
*/5 * * * * php /var/www/outing-system-private/check_overdue.php
*   * * * * * php /var/www/outing-system-private/check_time_alerts.php
```

Emails use PHP `mail()`. Configure `[mail function]` in `php.ini`, and use a dev mail catcher such as Mailpit or Mailtrap while testing.

## Student data and photos

- **Import students:** log in as admin → *Import students (CSV)*, upload a CSV with a header row. Columns: `std_no, std_name, ic_no, email, program, phone, emergency_contact_name, emergency_contact_phone`. Only `std_no`, `std_name`, `ic_no` are required. New students get the **last 6 digits of their IC number** as their first password and must change it at login. Existing students are updated without touching their password.
- **Photos:** upload through *Student photos* (admin). Files are saved as `outing-system-private/storage/photos/<std_no>.jpg` and are served only through the app, never directly.

## Settings

Admins can change these in the app (*System settings*): curfew start/end, late-return tolerance (minutes), QR refresh interval, and whether manual student-number entry at the gate is allowed.

## Upgrading an existing (older) database

Fresh installs skip this. If your database predates the current `schema.sql`, apply only what you are missing, in this order, from `database/migrations/`:

1. `migration_app_settings.sql`
2. `migration_special_checkin.sql`
3. `migration_notifications.sql`
4. `migration_qr_groups.sql`
5. `migration_auth_hardening.sql`

`migration_special_checkin.sql` and `migration_auth_hardening.sql` use plain `ALTER TABLE ... ADD COLUMN`, so running them on a database that already has those columns fails. That is harmless, but it means you should run each one only once.

## Before going to production

- Serve over **HTTPS**.
- Remove the demo data, or at least change every demo password.
- Create a dedicated MySQL user instead of `root`, and set a password in `.env`.
- Keep `outing-system-private/` outside the web root.
- Keep `.env`, student photos and log files out of version control (`.gitignore` already covers them).

## License

Add a `LICENSE` file before publishing. Without one, others have no permission to reuse the code.

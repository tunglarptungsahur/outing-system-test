-- Run this once against an existing database to add editable settings
-- (curfew window + special-outing tolerance) without dropping any
-- existing tables/data. Safe to re-run: CREATE TABLE IF NOT EXISTS
-- and INSERT IGNORE.

USE outing_system;

CREATE TABLE IF NOT EXISTS app_setting (
    setting_key   VARCHAR(64)  NOT NULL PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL,
    updated_by    VARCHAR(20)  NULL,
    updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO app_setting (setting_key, setting_value) VALUES
    ('curfew_start', '23:00:00'),
    ('curfew_end', '06:00:00'),
    ('special_tolerance_minutes', '15');

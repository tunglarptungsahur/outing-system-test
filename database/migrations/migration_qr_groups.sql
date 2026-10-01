-- Dynamic TOTP QR codes + group outings. Safe to re-run.
USE outing_system;

-- Per-subject server-side secret for rotating QR codes.
-- subject = 'S:<std_no>' (student QR) or 'G:<group_id>' (group Master QR).
-- last_ts = start time (unix) of the last accepted code window; a code
-- is only accepted if its window starts AFTER this (blocks replays of a
-- screenshot). Stored as a timestamp, not a counter, so changing the
-- refresh interval in settings only causes at most one refresh window
-- of "already used" errors for a student scanned just before the change.
CREATE TABLE IF NOT EXISTS qr_secret (
    subject     VARCHAR(40) NOT NULL PRIMARY KEY,
    secret      VARCHAR(64) NOT NULL,
    last_ts     BIGINT      NOT NULL DEFAULT 0,
    created_at  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS outing_group (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    lead_std_no   VARCHAR(20) NOT NULL,
    status        ENUM('active','closed') NOT NULL DEFAULT 'active',
    created_at    TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at    DATETIME    NOT NULL,
    CONSTRAINT fk_group_lead FOREIGN KEY (lead_std_no) REFERENCES student(std_no),
    INDEX idx_group_lead (lead_std_no, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A member must accept before they count towards the group, so nobody
-- can be checked out (and collect violations) under someone else's QR
-- without agreeing. Old declined/removed rows stay as history.
CREATE TABLE IF NOT EXISTS outing_group_member (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    group_id    INT NOT NULL,
    std_no      VARCHAR(20) NOT NULL,
    status      ENUM('invited','accepted','declined','removed','left') NOT NULL DEFAULT 'invited',
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    responded_at DATETIME NULL,
    CONSTRAINT fk_gm_group FOREIGN KEY (group_id) REFERENCES outing_group(id),
    CONSTRAINT fk_gm_student FOREIGN KEY (std_no) REFERENCES student(std_no),
    INDEX idx_gm_group (group_id, status),
    INDEX idx_gm_student (std_no, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per Master QR batch a guard processes (audit trail; each
-- member's own outing rows are still written by the normal gate ops).
CREATE TABLE IF NOT EXISTS outing_group_scan (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    group_id        INT NOT NULL,
    guard_id        VARCHAR(20) NOT NULL,
    gate_location   VARCHAR(100) NULL,
    processed_count INT NOT NULL DEFAULT 0,
    skipped_count   INT NOT NULL DEFAULT 0,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_gs_group FOREIGN KEY (group_id) REFERENCES outing_group(id),
    CONSTRAINT fk_gs_guard FOREIGN KEY (guard_id) REFERENCES staff(staff_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Who in a group went out / came back (updated from every gate check-out
-- and check-in, group QR or individual). When everyone who went out is
-- back, the group closes automatically (groups.php maybe_close_group()).
CREATE TABLE IF NOT EXISTS outing_group_log (
    group_id  INT NOT NULL,
    std_no    VARCHAR(20) NOT NULL,
    out_at    DATETIME NULL,
    in_at     DATETIME NULL,
    PRIMARY KEY (group_id, std_no),
    CONSTRAINT fk_gl_group FOREIGN KEY (group_id) REFERENCES outing_group(id),
    CONSTRAINT fk_gl_student FOREIGN KEY (std_no) REFERENCES student(std_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Links a trip (standard_outing.id or outing_request.id) to the group it
-- was part of, so outing history can label group outings.
CREATE TABLE IF NOT EXISTS outing_group_link (
    kind      ENUM('standard','special') NOT NULL,
    ref_id    INT NOT NULL,
    group_id  INT NOT NULL,
    PRIMARY KEY (kind, ref_id),
    INDEX idx_gl_group (group_id),
    CONSTRAINT fk_glink_group FOREIGN KEY (group_id) REFERENCES outing_group(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO app_setting (setting_key, setting_value) VALUES
    ('qr_refresh_seconds', '30'),
    ('allow_manual_gate_entry', '1');

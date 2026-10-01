-- Run once.
CREATE TABLE IF NOT EXISTS login_failure (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    account_type  ENUM('staff','student') NOT NULL,
    identifier    VARCHAR(64) NOT NULL,
    ip            VARCHAR(45) NOT NULL,
    attempted_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_account (account_type, identifier, attempted_at),
    INDEX idx_ip (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Staff: off by default (turn on for a reset account: UPDATE staff SET must_change_password = 1 WHERE staff_id = '...';)
ALTER TABLE staff   ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0;

-- Students: on by default, so every existing student and every CSV-imported
-- account (IC-number password) must set a new password at first login.
ALTER TABLE student ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 1;

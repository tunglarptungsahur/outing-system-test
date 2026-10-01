-- Header notification bell -- student-facing first.
-- Run against the existing outing_system database (matches schema.sql's
-- charset/engine conventions; std_no is the FK target, same as every
-- other table here).

USE outing_system;

CREATE TABLE IF NOT EXISTS notification (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    std_no           VARCHAR(20)  NOT NULL,
    type             VARCHAR(30)  NOT NULL, -- request_submitted, request_approved, request_rejected,
                                             -- time_alert_60, time_alert_30, time_alert_10, time_alert_due,
                                             -- violation
    message          VARCHAR(255) NOT NULL,
    reference_table  VARCHAR(30)  NULL,     -- outing_request | standard_outing
    reference_id     INT          NULL,
    is_read          TINYINT(1)   NOT NULL DEFAULT 0,
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notification_student FOREIGN KEY (std_no)
        REFERENCES student(std_no) ON DELETE CASCADE,
    INDEX idx_notification_unread (std_no, is_read),
    INDEX idx_notification_dedupe (std_no, type, reference_table, reference_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

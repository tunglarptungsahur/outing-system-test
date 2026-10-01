-- Migration: split the special/curfew track's outing_request into two
-- independent permission types instead of one forced round trip.
-- Run this ONCE against your existing database (it does not drop or
-- recreate anything, so existing data is preserved).
--
-- Every existing row gets type = 'checkout' by default, which is
-- correct: the old model tracked exactly what a checkout-type request
-- tracks now (both requested_out_at and expected_return_at were
-- always required), so nothing about existing rows changes in
-- meaning. Only new checkin-type requests going forward use the
-- lighter shape (expected_return_at only).

ALTER TABLE outing_request
    ADD COLUMN type ENUM('checkout','checkin') NOT NULL DEFAULT 'checkout' AFTER std_no,
    MODIFY requested_out_at   DATETIME NULL,
    MODIFY expected_return_at DATETIME NULL,
    ADD INDEX idx_request_type (type);

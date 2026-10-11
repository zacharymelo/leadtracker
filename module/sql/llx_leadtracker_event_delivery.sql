-- Copyright (C) 2026 Lead Tracker contributors
-- GPL-3.0-or-later

-- Delivery state of a stage event for one destination (the generic webhook keeps
-- its own state on the event row). One row per event and destination.
-- status: 0 pending, 1 sent, 2 failed (gave up)

CREATE TABLE IF NOT EXISTS llx_leadtracker_event_delivery (
    rowid          INT            NOT NULL AUTO_INCREMENT PRIMARY KEY,
    fk_event       INT            NOT NULL,
    destination    VARCHAR(32)    NOT NULL,
    status         SMALLINT       NOT NULL DEFAULT 0,
    attempts       SMALLINT       NOT NULL DEFAULT 0,
    date_last_try  DATETIME       NULL,
    date_sent      DATETIME       NULL,
    remote_id      VARCHAR(128)   NULL,
    last_error     VARCHAR(255)   NULL,
    tms            TIMESTAMP      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_lt_ed_event_dest (fk_event, destination),
    KEY idx_lt_ed_status (destination, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

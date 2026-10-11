-- Copyright (C) 2026 Lead Tracker contributors
-- GPL-3.0-or-later

-- One row per pipeline stage change of a tracked lead. Doubles as the stage
-- history of the lead and as the delivery queue of the outbound webhook.
-- delivery_status: 0 pending, 1 sent, 2 failed (gave up), 3 not to be sent

CREATE TABLE IF NOT EXISTS llx_leadtracker_stage_event (
    rowid            INT            NOT NULL AUTO_INCREMENT PRIMARY KEY,
    entity           INT            NOT NULL DEFAULT 1,
    fk_project       INT            NOT NULL,
    fk_stage_from    INT            NULL,
    fk_stage_to      INT            NOT NULL,
    source           VARCHAR(16)    NOT NULL DEFAULT 'auto',
    opp_percent      DOUBLE(5,2)    NULL,
    opp_amount       DOUBLE(24,8)   NULL,
    date_event       DATETIME       NOT NULL,
    delivery_status  SMALLINT       NOT NULL DEFAULT 0,
    attempts         SMALLINT       NOT NULL DEFAULT 0,
    date_last_try    DATETIME       NULL,
    date_sent        DATETIME       NULL,
    last_error       VARCHAR(255)   NULL,
    tms              TIMESTAMP      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_lt_se_project (fk_project),
    KEY idx_lt_se_delivery (delivery_status, entity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

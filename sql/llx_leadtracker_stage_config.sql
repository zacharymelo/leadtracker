-- Copyright (C) 2026 Lead Tracker contributors
-- GPL-3.0-or-later

CREATE TABLE IF NOT EXISTS llx_leadtracker_stage_config (
    rowid          INT            NOT NULL AUTO_INCREMENT PRIMARY KEY,
    fk_lead_status INT            NOT NULL,
    condition_type VARCHAR(64)    NOT NULL,
    active         SMALLINT       NOT NULL DEFAULT 1,
    entity         INT            NOT NULL DEFAULT 1,
    tms            TIMESTAMP      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_lt_fk_lead_status (fk_lead_status),
    KEY idx_lt_entity (entity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

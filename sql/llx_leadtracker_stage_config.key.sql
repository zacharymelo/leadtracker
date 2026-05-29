-- Copyright (C) 2026 Lead Tracker contributors
-- GPL-3.0-or-later

ALTER TABLE llx_leadtracker_stage_config ADD INDEX idx_lt_fk_lead_status (fk_lead_status);
ALTER TABLE llx_leadtracker_stage_config ADD INDEX idx_lt_entity (entity);

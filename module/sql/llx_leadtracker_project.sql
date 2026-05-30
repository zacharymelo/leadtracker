-- Lead Tracker per-project settings
-- auto_sync = 1 (default, no row needed): recalculate values on every trigger event
-- auto_sync = 0: skip automatic value writes; user manages amount/percent manually

CREATE TABLE IF NOT EXISTS llx_leadtracker_project (
  rowid       int(11)    NOT NULL AUTO_INCREMENT,
  fk_project  int(11)    NOT NULL,
  auto_sync   tinyint(1) NOT NULL DEFAULT 1,
  tms         timestamp  DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (rowid),
  UNIQUE KEY uk_leadtracker_project_fk (fk_project)
) ENGINE=InnoDB;

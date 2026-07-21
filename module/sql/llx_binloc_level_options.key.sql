-- Copyright (C) 2026 Zachary Melo
--
-- Binloc: level options indexes and constraints
--

ALTER TABLE llx_binloc_level_options ADD UNIQUE INDEX uk_binloc_lvl_opt (fk_level, value);
ALTER TABLE llx_binloc_level_options ADD INDEX idx_binloc_lvl_opt_level (fk_level);
ALTER TABLE llx_binloc_level_options ADD CONSTRAINT fk_binloc_lvl_opt_level FOREIGN KEY (fk_level) REFERENCES llx_binloc_warehouse_levels (rowid) ON DELETE CASCADE;

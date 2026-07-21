-- Copyright (C) 2026 Zachary Melo
--
-- Binloc: location value indexes and constraints
-- fk_option has no ON DELETE action on purpose: deleting an option that is
-- still referenced must fail loudly; the UI offers deactivation instead.
--

ALTER TABLE llx_binloc_location_value ADD UNIQUE INDEX uk_binloc_loc_val (fk_location, fk_level);
ALTER TABLE llx_binloc_location_value ADD INDEX idx_binloc_loc_val_level (fk_level);
ALTER TABLE llx_binloc_location_value ADD INDEX idx_binloc_loc_val_option (fk_option);
ALTER TABLE llx_binloc_location_value ADD CONSTRAINT fk_binloc_loc_val_location FOREIGN KEY (fk_location) REFERENCES llx_binloc_product_location (rowid) ON DELETE CASCADE;
ALTER TABLE llx_binloc_location_value ADD CONSTRAINT fk_binloc_loc_val_level FOREIGN KEY (fk_level) REFERENCES llx_binloc_warehouse_levels (rowid) ON DELETE CASCADE;
ALTER TABLE llx_binloc_location_value ADD CONSTRAINT fk_binloc_loc_val_option FOREIGN KEY (fk_option) REFERENCES llx_binloc_level_options (rowid);

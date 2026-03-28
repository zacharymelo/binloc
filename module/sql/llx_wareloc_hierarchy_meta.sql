-- Copyright (C) 2026 Zachary Melo
--
-- llx_wareloc_hierarchy_meta
-- Stores depth-level labels for a warehouse tree root (or global default).
-- fk_entrepot NULL  = global default used when a root has no custom labels
-- fk_entrepot = X   = label set specific to root warehouse X
-- depth             = 1 (outermost / broadest) .. N (leaf / bin level)

CREATE TABLE IF NOT EXISTS llx_wareloc_hierarchy_meta (
    rowid           INTEGER         NOT NULL AUTO_INCREMENT PRIMARY KEY,
    entity          INTEGER         NOT NULL DEFAULT 1,
    fk_entrepot     INTEGER         DEFAULT NULL,
    depth           SMALLINT        NOT NULL,
    label           VARCHAR(64)     NOT NULL,
    date_creation   DATETIME        NOT NULL,
    fk_user_creat   INTEGER         NOT NULL
) ENGINE=InnoDB;

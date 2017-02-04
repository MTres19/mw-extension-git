-- Add page merge columns to logging table for easier querying
ALTER TABLE /*_*/logging
    ADD COLUMN log_merge_destination VARBINARY(255),
    ADD COLUMN log_merge_destination_namespace INTEGER,
    ADD COLUMN log_merge_mergepoint VARBINARY(14);

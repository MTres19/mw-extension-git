-- git_tag table
-- 
-- Used to store Git tags
-- 
-- Git tags won't be displayed in MediaWiki but they're
-- not hard to store, so why not?

CREATE TABLE IF NOT EXISTS /*_*/git_tag(
    -- Blob to store the actual tag content since it
    -- doesn't need processing.
    tag_data BLOB NOT NULL,
    
    -- Name of the tag ref
    tag_name VARBINARY(127)
)/*$wgDBTableOptions*/;

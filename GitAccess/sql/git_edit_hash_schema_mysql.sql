-- git_edit_hash table
-- 
-- Used to record the relationship between edits and commits.
-- 
-- As Git allows multiple pages to be changed in a single commit,
-- MediaWiki revisions made by Git commit have to be bound to a
-- single commit hash to keep repositories in sync. A bit more 
-- info on the background of this can be found in the comments for
-- git_status_modify_hash.


CREATE TABLE IF NOT EXISTS /*_*/git_edit_hash(
    -- The commit connected to this edit, in case of multiple
    -- modifications per commit
    commit_hash VARBINARY(40) NOT NULL,
    
    -- The revision ID referenced by the commit. This can't be a foreign
    -- key as that would prevent pages being moved to the archive table.
    -- The page ID can be found from the revision table.
    affected_rev_id INTEGER,
    FOREIGN KEY (commit_hash) REFERENCES git_hash(commit_hash)
)/*$wgDBTableOptions*/;
    

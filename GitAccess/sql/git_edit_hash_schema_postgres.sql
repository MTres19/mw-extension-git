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
    commit_hash VARCHAR(40) NOT NULL FOREIGN KEY REFERENCES git_hash(commit_hash),
    commit_hash_parent VARCHAR(40),
    affected_rev_id INTEGER,
    affected_page_id INTEGER
) /*$wgDBTableOptions*/;
    

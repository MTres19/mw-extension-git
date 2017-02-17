-- git_status_modify_hash table
-- 
-- Used to log changes to page status (i.e. deletions)
-- 
-- These aren't stored as edits and therefore can't be automatically
-- generated on-the-fly. (Note that pages in the File namespace are
-- in fact regular pages.) These actions can't be generated from the
-- logging table because local commits could include multiple changes
-- and there is no way to mark these changes as being part of the same
-- commit. Also, Git stores a committer in addition to an author for
-- a commit. The same problems hold true for edits.

CREATE TABLE IF NOT EXISTS /*_*/git_status_modify_hash(
    -- Foreign key to commit_hash in the git_hash table. This assigns an action
    -- like a page move to a commit, in case it is part of a single commit that
    -- includes other modifications.
    commit_hash VARBINARY(40) NOT NULL FOREIGN KEY REFERENCES git_hash(commit_hash),
    
    -- Foreign key to log_id in MediaWiki's logging table. From this key GitAccess
    -- can infer all the information it needs about the change itself.
    log_id INTEGER NOT NULL FOREIGN KEY REFERENCES logging(log_id)
)/*$wgDBTableOptions*/;

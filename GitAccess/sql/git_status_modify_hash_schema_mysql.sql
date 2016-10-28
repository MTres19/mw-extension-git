-- git_status_modify_hash table
-- 
-- Used to log changes to page status (i.e. moving and deleting).
-- 
-- These aren't stored as edits and therefore can't be automatically
-- generated on-the-fly. These actions can't be generated from the
-- logging table because local commits could include multiple changes
-- and there is no way to mark these changes as being part of the same
-- commit. Also, Git stores a committer in addition to an author for
-- a commit. The same problems hold true for edits.

CREATE TABLE IF NOT EXISTS /*_*/git_status_modify_hash(
    commit_hash VARCHAR(40) NOT NULL,
    log_id INTEGER NOT NULL,
    FOREIGN KEY (commit_hash) REFERENCES git_hash(commit_hash),
    FOREIGN KEY (log_id) REFERENCES logging(log_id)
)/*$wgDBTableOptions*/;

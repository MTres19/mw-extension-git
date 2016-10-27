-- git_hash table
-- 
-- Stores ONLY metadata about commits that MediaWiki CANNOT store.
-- 
-- The other tables git_status_modify_hash and git_edit_hash store
-- actual CHANGES of a commit that MediaWiki cannot store or cannot
-- store in a suitable format.

CREATE TABLE IF NOT EXISTS /*_*/git_hash(
    commit_hash VARCHAR(40) NOT NULL PRIMARY KEY,
    commit_hash_parent VARCHAR(40),
    author_email VARCHAR(255),
    committer_name VARCHAR(255),
    committer_email VARCHAR(255)
) /*$wgDBTableOptions*/;

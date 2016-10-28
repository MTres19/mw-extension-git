-- git_hash table
-- 
-- Stores ONLY metadata about commits that MediaWiki CANNOT store.
-- 
-- The other tables git_status_modify_hash and git_edit_hash store
-- actual CHANGES of a commit that MediaWiki cannot store or cannot
-- store in a suitable format.

CREATE TABLE IF NOT EXISTS /*_*/git_hash(
    -- The primary key, contains the Git commit hash in a 40-character
    -- hex representation of SHA-1
    commit_hash VARCHAR(40) NOT NULL PRIMARY KEY,
    
    -- Parent commit hashes (up to 15, separated by commas) in 40-char
    -- hex of SHA-1
    commit_hash_parents VARCHAR(615),
    
    -- Email addresses and usernames can be changed in MediaWiki,
    -- however this shouldn't change every previous commit (since that would
    -- change the hashes). Also necessary for edits made via pull requests.
    author_name VARCHAR(255),
    author_email VARCHAR(255),
    
    -- With rebases sometimes you have different authors and committers. This
    -- has to be stored somehow to keep the "real" Git repository in sync.
    committer_name VARCHAR(255),
    committer_email VARCHAR(255)
) /*$wgDBTableOptions*/;
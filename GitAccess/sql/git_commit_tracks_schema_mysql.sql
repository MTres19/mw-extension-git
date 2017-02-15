-- git_commit_tracks
-- 
-- Allows pushing commits with multiple parents to the wiki.
-- Example diagram
-- 
-- master [Commit A] --> [Commit B] --> [Commit C] --> [Commit D]
--                \                                     ^
--                 \                                   /
-- fork             \--> [Commit 1] --> [Commit 2] ---/
-- 
-- The problem is that all those commits need corresponding MediaWiki
-- revisions. MediaWiki has no concept of revisions having multiple
-- parents, so what this table does is store a map of commits to change
-- tags. Change tags have the format git-branch-tracker-<SHA-1 of first commit>.

CREATE TABLE IF NOT EXISTS /*_*/git_commit_tracks(
    -- The commit being assigned a MediaWiki change tag
    commit_hash VARBINARY(40) NOT NULL,
    
    -- The change tag being used
    associated_tag VARBINARY(255),
    
    FOREIGN KEY (commit_hash) REFERENCES git_hash(commit_hash),
    FOREIGN KEY (associated_tag) REFERENCES valid_tag(vt_tag)
)/*$wgDBTableOptions*/;

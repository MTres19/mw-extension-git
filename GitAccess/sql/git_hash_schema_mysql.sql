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
    commit_hash VARBINARY(40) NOT NULL PRIMARY KEY,
    
    -- Parent commit hashes (up to 15, separated by commas) in 40-char
    -- hex of SHA-1
    commit_hash_parents VARBINARY(615),
    
    -- Email addresses and usernames can be changed in MediaWiki,
    -- however this shouldn't change every previous commit (since that would
    -- change the hashes). Also necessary for edits made via pull requests.
    author_name VARBINARY(255),
    author_email VARBINARY(255),
    
    -- Timestamps need to be easily fetched for commits without looking up log
    -- entries or revisions. Unix time format.
    author_timestamp INTEGER,
    author_tzOffset INTEGER,
    
    -- With rebases sometimes you have different authors and committers. This
    -- has to be stored somehow to keep the "real" Git repository in sync.
    committer_name VARBINARY(255),
    committer_email VARBINARY(255),
    committer_timestamp INTEGER,
    committer_tzOffset INTEGER,
    
    -- Git never walks forward in a commit history, because it's very difficult
    -- to find child commits. By storing the HEAD commit, it's simple to walk
    -- backward through the parents.
    is_head BOOLEAN,
    
    -- The GitAccess_root:Aliases page's revision ID for this commit. Storing this
    -- here makes it immensely simpler than trying to figure out which revision ID
    -- is the Aliases page from the git_edit_hash table. NULL if unneeded.
    aliases_rev_id INTEGER,
    
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
    -- 
    -- This field stores the hash of the farthest back parent commit that
    -- "split off" from the main branch. (Which branch is the "main" branch
    -- is arbitrarily chosen because commits aren't tied to a specific branch.)
    -- For commits added from the revision log, this field will be the same
    -- as that of what was the current HEAD commit at the time. For commits
    -- pushed from a Git client, the history will have to be examined to determine
    -- the track. For the very first default revision, this will be the same as
    -- the commit hash.
    commit_track VARBINARY(40),
    
    -- Allows overriding up to 5 revisions to be the latest for the page at the time
    -- of the commit. (Separated by commas). The only two entries that might go in here
    -- for now are (1) an Aliases.xml that GitAccess had to update after the revision
    -- corresponding to this commit was made or (2) a revision ID that is now the latest
    -- one after a page was restored.
    revs_override VARBINARY(9),
    
    -- Analogous to revs_override, but stores up to 5 image timestamps separated by
    -- commas. Currently I can think of only one entry that would go here, that is,
    -- for restored images that are not the latest version.
    img_timestamp_override VARBINARY(74)
)/*$wgDBTableOptions*/;

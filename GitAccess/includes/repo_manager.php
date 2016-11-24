<?php
/**
 * GitAccess MediaWiki Extension---Access wiki content with Git.
 * Copyright (C) 2016  Matthew Trescott
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published
 * by the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 * 
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
 
class GitRepository
{
    public $blobs;
    public $trees;
    public $commits;
    public $tags;
    private $dbw;
    
    public function __construct(&$dbw)
    {
        $this->dbw = $dbw;
        $this->blobs => array();
        $this->trees => array();
        $this->commits => array();
        $this->tags => array();
    }
    
    public function populateFromJournal()
    {
        $result = $this->dbw->select('git_hash', '*', null);
        $hashes = array();
        do
        {
            $row = $result->fetchRow();
            if ($row)
            {
                $hashes[$row['commit_hash']] = array(
                    'commit_hash_parents' => explode(',', $row['commit_hash_parents']),
                    'author_name' => $row['author_name'],
                    'author_email' => $row['author_email'],
                    'author_timestamp' => $row['author_timestamp'],
                    'author_tzOffset' => $row['author_tzOffset'],
                    'committer_name' => $row['committer_name'],
                    'committer_email' => $row['committer_email'],
                    'committer_timestamp' => $row['committer_timestamp'],
                    'committer_tzOffset' => $row['committer_tzOffset']
                );
            }
        }
        while ($row);
        
        foreach ($hashes as $hash => &$hash_data)
        {
            $timestamp = wfTimestamp(
                TS_MW,
                $hash_data['committer_timestamp'] + $hash_data['committer_tzOffset']
            );
            /*// -----------------------------------------------
            // Process objects  for revisions
            $affected_rev_ids = $this->dbw->select(
                'git_edit_hash',
                'affected_rev_id',
                array('commit_hash' => $hash)
            );
            
            $rev_ids = array();
            do
            {
                $row = $affected_rev_ids->fetchRow()
                if ($row)
                {
                    array_push($rev_ids, $row['affected_rev_id']);
                }
            }
            while ($row);
            $this->loadFromRevisions($rev_ids, $hash_data);
            
            // ------------------------------------------------
            // Process objects for status modifications
            $linked_log_ids = $this->dbw->select(
                'git_status_modify_hash',
                'log_id',
                array('commit_hash' => $hash)
            );
            
            $log_ids = array();
            do
            {
                $row = $linked_log_ids->fetchRow();
                if ($row)
                {
                    array_push($log_ids, $row['log_id']);
                }
            }
            while ($row);
            $this->loadFromLogging($log_ids, $hash_data);*/
        }
    }
    
    public function loadFromRevisions($rev_ids, &$hash_data)
    {
        foreach ($rev_ids as $rev_id)
        {
            $revision = Revision::newFromId($rev_id);
            $text = ContentHandler::getContentText($revision->getContent(Revision::RAW));
        }
    }
    
    public function loadFromLogging($log_ids, &$hash_data)
    {
        
    }
    
    public static function createBlobObject($data)
    {
        $blob = 'blob ' . strlen($data) . "\0" . $data;
        $hash_bin = hash('sha1', $blob, true);
        $hash_hex = bin2hex($hash_bin);
        return array('blob' => $blob, 'hash_hex' => $hash_hex, 'hash_bin' => $hash_bin);
    }
    
    public static function readBlobObject($blob)
    {
        sscanf($blob, "blob %d\0", $length);
        $data = substr(
            $blob,
            strpos($blob, "\0") + 1,
            $length
        );
        return $data;
    }
    
    public static function createTreeObject($tree_data)
    {
        $tree = '';
        
        foreach ($tree_data as $entry)
        {
            switch ($entry['type'])
            {
                case 'NORMAL_FILE':
                    $tree .= '100644';
                    break;
                case 'EXEC_FILE':
                    $tree .= '100755';
                    break;
                case 'SYMLINK':
                    $tree .= '120000';
                    // Not sure where this would be used, but for completeness...
                    break;
                case 'TREE':
                    $tree .= '40000';
                    break;
            }
            
            $tree = $tree . ' ' . $entry['name'] . "\0" . $entry['hash_bin'];
        }
        
        $length = strlen($tree);
        $tree = 'tree ' . $length . "\0" . $tree;
        $hash_bin = hash('sha1', $tree, true);
        $hash_hex = bin2hex($hash_bin);
        
        return array('tree' => $tree, 'hash_hex' => $hash_hex, 'hash_bin' => $hash_bin);
    }
    
    public static function readTreeObject($tree)
    {
        sscanf($tree, "tree %d\0", $length);
        $raw_entries = substr(
            $tree,
            strpos($tree, "\0") + 1,
            $length
        );
        $raw_bytes = str_split($raw_entries);
        
        $tree_data = array();
        
        for ($i = 0; isset($raw_entries[$i]); ++$i)
        {
            /* Either the null is marking the beginning of the hash,
             * or it is part of the hash itself. However, the latter
             * case should not appear, since $i will be pushed past
             * the hash automatically upon encountering any NUL char.
             */
            if ($raw_bytes[$i] === "\0")
            {
                if (!isset($beginning_of_entry))
                {
                    /* "Rewind" to beginning of string to get type and name.
                     * This relies on a subtle difference between isset() and
                     * empty().
                     */
                    $beginning_of_entry = 0;
                }
                
                sscanf(
                    substr($raw_entries, $beginning_of_entry, $i - $beginning_of_entry),
                    "%d %s[^\t\n]",
                    $type_id,
                    $filename
                );
                
                switch ($type_id)
                {
                    case 100644:
                        $type = 'NORMAL_FILE';
                        break;
                    case 100755:
                        $type = 'EXEC_FILE';
                        break;
                    case 120000:
                        $type = 'SYMLINK';
                        break;
                    case 40000:
                        $type = 'TREE';
                        break;
                }
                
                $hash_bin = substr($raw_entries, $i + 1, 20);
                $hash_hex = bin2hex($hash_bin);
                
                $file_entry = array('type' => $type, 'name' => $filename, 'hash_bin' => $hash_bin, 'hash_hex' => $hash_hex);
                array_push($tree_data, $file_entry);
                
                $i = $i + 21; // Push $i past the hash
                $beginning_of_entry = $i;
            }
        }
        return $tree_data;
    }
    
    public static function createCommitObject(
        $tree,
        $parents,
        $author_name,
        $author_email,
        $author_timestamp,
        $author_tzOffset,
        $committer_name,
        $committer_email,
        $committer_timestamp,
        $committer_tzOffset,
        $message
    )
    {
        $commit = "tree $tree\n";
        foreach($parents as $parent)
        {
            $commit .= "parent $parent\n";
        }
        
        $author_tstamp_unix = wfTimestamp(TS_UNIX, $author_timestamp);
        $committer_tstamp_unix = wfTimestamp(TS_UNIX, $committer_timestamp);
        
        $commit .= "author $author_name <$author_email> $author_tstamp_unix $author_tzOffset\n";
        $commit .= "committer $committer_name <$committer_email> $committer_tstamp_unix $committer_tzOffset";
        $commit .= "\n\n";
        $commit .= $message;
        
        $length = strlen($commit);
        
        $commit = "commit $length" . "\0". $commit;
        
        $hash_bin = hash('sha1', $commit, true);
        $hash_hex = bin2hex($hash_bin);
        
        return array('commit' => $commit, 'hash_hex' => $hash_hex, 'hash_bin' => $hash_bin);
    }
    
    public static function readCommitObject($commit)
    {
        sscanf($commit, "commit %d\0", $length);
        $raw_data = substr(
            $commit,
            strpos($commit, "\0") + 1,
            $length
        );
        sscanf($raw_data, "tree %s\n", $tree);
        $pieces = explode("\n", $raw_data);
        
        $parents = array();
        $last_parent_piece = null;
        for ($i = 1; preg_match("~^parent .{40}$~", $pieces[$i]) === 1; ++$i)
        {
            sscanf($pieces[$i], "parent %40s", $parents[$i - 1]);
            $last_parent_piece = $i;
        }
        
        $author_data = array();
        preg_match(
            "~^author (.*) <(.*@.*\..*)> ([0-9]*) ([0-9+-]*)$~",
            $pieces[$last_parent_piece + 1],
            $author_data
        );
        
        $committer_data = array();
        preg_match(
            "~^committer (.*) <(.*@.*\..*)> ([0-9]*) ([0-9+-]*)$~",
            $pieces[$last_parent_piece + 2],
            $committer_data
        );
        
        $msg_start = strpos($raw_data, "\n\n") + 2;
        $message = substr(
            $raw_data,
            $msg_start,
            $length - $msg_start
        );
        
        return array(
            'tree' => $tree,
            'parents' => $parents,
            'author_name' => $author_data[1],
            'author_email' => $author_data[2],
            'author_timestamp' => $author_data[3],
            'author_tzOffset' => $author_data[4],
            'committer_name' => $committer_data[1],
            'committer_email' => $committer_data[2],
            'committer_timestamp' => $committer_data[3],
            'committer_tzOffset' => $committter_data[4],
            'message' => $message
        );
    }
}


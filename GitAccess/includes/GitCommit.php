<?php
/**
 * GitAccess MediaWiki Extension---Access wiki content with Git.
 * Copyright (C) 2017  Matthew Trescott
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

class GitCommit
{
    public $commit_hash;
    public $parent_hashes;
    public $author_name;
    public $author_email;
    public $author_timestamp;
    public $author_tzOffset;
    public $committer_name;
    public $committer_email;
    public $committer_timestamp;
    public $committer_tzOffset;
    
    public $commit_message;
    
    public $linked_rev_ids;
    public $linked_log_ids;
    
    public $root_tree;
    public $root_tree_hash;
    public $is_head;
    
    protected $dbw;
    protected $repo;
    
    public function __construct()
    {
        $this->repo = &GitRepository::singleton();
        $this->dbw = wfGetDB(DB_MASTER);
    }
    
    protected function populateIdsFromRevision()
    {
        $linked_rev_result = $this->dbw->select(
            'git_edit_hash',
            'affected_rev_id',
            array('commit_hash' => $this->commit_hash)
        );
        
        $this->linked_rev_ids = array();
        do
        {
            $row = $linked_rev_result->fetchRow();
            if ($row)
            {
                array_push($this->linked_rev_ids, $row['affected_rev_id']);
            }
        }
        while ($row);
    }
    
    protected function populateIdsFromLogging()
    {
        $linked_log_result = $this->dbw->select(
            'git_status_modify_hash',
            'log_id',
            array('commit_hash' => $this->commit_hash)
        );
        
        $this->linked_log_ids = array();
        do
        {
            $row = $linked_log_result->fetchRow();
            if ($row)
            {
                array_push($this->linked_log_ids, $row['log_id']);
            }
        }
        while ($row);
    }
    
    public function export()
    {
        $commit = 'tree '. $this->root_tree->getHash() . "\n";
        foreach($this->parent_hashes as $parent)
        {
            $commit .= 'parent ' . $parent . "\n";
        }
        
        $author_tstamp_unix = wfTimestamp(TS_UNIX, $this->author_timestamp);
        $committer_tstamp_unix = wfTimestamp(TS_UNIX, $this->committer_timestamp);
        
        $commit .=
            'author ' .
            $this->author_name .
            ' <' .
            $this->author_email .
            '> ' .
            $author_tstamp_unix .
            ' ' .
            self::tzOffsetToHrsMins($this->author_tzOffset) .
            "\n";
        $commit .=
            'committer ' .
            $this->committer_name .
            ' <' .
            $this->committer_email .
            '> ' .
            $committer_tstamp_unix .
            ' ' .
            self::tzOffsetToHrsMins($this->committer_tzOffset) .
            "\n";
        $commit .= "\n\n";
        $commit .= $this->commit_message;
        
        $length = strlen($commit);
        
        $commit = "commit $length" . "\0". $commit;
        
        return $commit;
    }
    
    public function getHash()
    {
        return hash('sha1', $this->export());
    }
    
    public function addToRepo()
    {
        $this->repo->commits[$this->getHash()] = $this;
    }
    
    public static function newFromData($commit)
    {
        $commit_data = array();
        preg_match(
            "commit (.[0-9]*)\\0tree (.{40})\\n((?:parent .{40}\\n)*)author (.*) <(.*@.*\..*)> ([0-9]*) ([0-9+-]*)\\ncommitter (.*) <(.*@.*\..*)> ([0-9]*) ([0-9+-]*)\\n\\n(.*)",
            $commit,
            $commit_data
        );
        
        $parents = array();
        $parent_pieces = explode("\n", $commit_data[2]);
        foreach($parent_pieces as $parent_piece)
        {
            array_push($parents, sscanf($parent_piece, "parent %s")[0]);
        }
        
        $hash = hash('sha1', $commit);
        
        $instance = new self();
        $instance->commit_hash = $hash;
        $instance->parent_hashes = $parents;
        $instance->author_name = $commit_data[3];
        $instance->author_email = $commit_data[4];
        $instance->author_timestamp = $commit_data[5];
        $instance->author_tzOffset = self::tzOffsetToUnix($commit_data[6]);
        $instance->committer_name = $commit_data[7];
        $instance->committer_email = $commit_data[8];
        $instance->committer_timestamp = $commit_data[9];
        $instance->committer_tzOffset = self::tzOffsetToUnix($commit_data[10]);
        $instance->commit_message = $commit_data[11];
        $instance->root_tree_hash = $commit_data[1];
        
        return $instance;
    }
    
    public static function newFromHashJournal($hash)
    {
        $instance = new self();
        $row = $instance->dbw->selectRow(
            'git_hash',
            array(
                'commit_hash_parents',
                'author_name',
                'author_email',
                'author_timestamp',
                'author_tzOffset',
                'committer_name',
                'committer_email',
                'committer_timestamp',
                'committer_tzOffset'
            ),
            array('commit_hash' => $hash)
        );
        
        $instance->commit_hash = $hash;
        $instance->parent_hashes = explode(',', $row->commit_hash_parents);
        $instance->author_name = $row->author_name;
        $instance->author_email = $row->author_email;
        $instance->author_timestamp = $row->author_timestamp;
        $instance->author_tzOffset = $row->author_tzOffset;
        $instance->committer_name = $row->committer_name;
        $instance->committer_email = $row->committer_email;
        $instance->committer_timestamp = $row->committer_timestamp;
        $instance->committer_tzOffset = $row->committer_tzOffset;
        $instance->is_head = (boolean)$row->is_head;
        
        return $instance;
    }
    
    public static function newFromRevId($id, $previous_log_id)
    {
        $instance = new self();
        
        $sql = $this->dbw-selectSQLText(
            'revision',
            array(
                'ar_id' => 'NULL',
                'rev_id' => 'rev_id'
            ),
            'rev_id = ' . $id
        );
        $sql .= ' UNION ';
        $sql .= $this->dbw->selectSQLText(
            'archive',
            array(
                'ar_id' => 'ar_id',
                'rev_id' => 'ar_rev_id'
            ),
            'ar_rev_id = ' . $id
        );
        $row = $this->dbw->query($sql)->fetchObject();
        
        $revision = $row->ar_id
                        ? Revision::newFromArchiveRow(
                            $this->dbw->selectRow(
                                'archive',
                                '*',
                                'ar_rev_id = ' . $id
                            )
                            )
                        : Revision::newFromId($id);
        
        $instance->linked_rev_ids = array($id);
        
        $instance->commit_message = $revision->getComment(Revision::RAW);
        if ($revision->isMinor())
        {
            $instance->commit_message = '[Minor] ' . $instance->commit_message;
        }
        
        $user = User::newFromId($revision->getUser(Revision::RAW));
        
        $instance->author_name = $user->getRealName() ?: $user->getName();
        $instance->author_email = $user->getEmail() ?: $user->getName() . '@' . $GLOBALS['wgServerName'];
        $instance->author_timestamp = wfTimestamp(TS_UNIX, $revision->getTimestamp());
        $instance->author_tzOffset = self::tzOffsetMwToUnix($user->getOption('timecorrection', $GLOBALS['wgLocalTZoffset'], true));
        $instance->committer_name = $user->getRealName() ?: $user->getName();
        $instance->committer_email = $user->getEmail() ?: $user->getName() . '@' . $GLOBALS['wgServerName'];
        $instance->committer_timestamp = wfTimestamp(TS_UNIX, $revision->getTimestamp());
        $instance->committer_tzOffset = self::tzOffsetMwToUnix($user->getOption('timecorrection', $GLOBALS['wgLocalTZoffset'], true));
        
        $instance->root_tree = GitTree::newRoot($id, $previous_log_id);
        
        return $instance;
    }
    
    public static function tzOffsetToUnix($tzOffset)
    {
        $sign = substr($tzOffset, 0, 1);
        $hours = substr($tzOffset, 1, 2);
        $minutes = substr($tzOffset, 3, 2);
        
        if ($sign === '-')
        {
            $hours *= -1;
            $minutes *= -1;
        }
        
        return ($minutes * 60) + ($hours * 3600);
    }
    
    public static function tzOffsetMwToUnix($tzOffset)
    {
        preg_match("~^(.+)\\|(.+)$~", $tzOffset, $info);
        return isset($info[2]) ? $info[2] * 60 : $tzOffset * 60;
    }
    
    public static function tzOffsetToHrsMins($tzOffset)
    {
        if ($tzOffset < 0)
        {
            $sign = '-';
            $tzOffset *= -1; // Prevent negative signs in output
        }
        else
        {
            $sign = '+';
        }
        
        $minutes = (string)(($tzOffset % 3600) / 60);
        $minutes = str_pad($minutes, 2, '0', STR_PAD_LEFT);
        $hours = (string)floor($tzOffset / 3600);
        $hours = str_pad($hours, 2, '0', STR_PAD_LEFT);
        
        return $sign . $hours . $minutes;
    }
}

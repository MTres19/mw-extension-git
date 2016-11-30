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
    
    protected $dbw;
    
    public function __construct($hash)
    {
        $this->commit_hash = $hash;
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
    
    public static function newFromData($commit)
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
        
        $hash = hash('sha1', $commit);
        
        $instance = new self();
        $instance->commit_hash = $hash;
        $instance->parent_hashes = $parents;
        $instance->author_name = $author_data[1];
        $instance->author_email = $author_data[2];
        $instance->author_timestamp = $author_data[3];
        $instance->author_tzOffset = self::tzOffsetToUnix($author_data[4]);
        $instance->committer_name = $committer_data[1];
        $instance->committer_email = $committer_data[2];
        $instance->committer_timestamp = $committer_data[3];
        $instance->committer_tzOffset = self::tzOffsetToUnix($committer_data[4]);
        $instance->commit_message = $message;
        
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

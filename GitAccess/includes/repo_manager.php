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
        $result = $this->dbw->select('git_hash', 'commit_hash');
        $hashes = array();
        do
        {
            $row = $result->fetchRow();
            if ($row)
            {
                $this->commits[$row['commit_hash']] = GitCommit::newFromHashJournal($row['commit_hash']);
            }
        }
        while ($row);
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
}


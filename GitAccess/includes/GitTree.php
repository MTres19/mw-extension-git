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

class GitTree
{
    public $tree_data;
    
    protected $repo;
    protected $dbw;
    
    const T_NORMAL_FILE = 100644;
    const T_EXEC_FILE = 100755;
    const T_SYMLINK = 120000;
    const T_TREE = 40000;
    
    public function __construct()
    {
        $this->repo = &GitRepository::singleton();
        $this->dbw = wfGetDB(DB_MASTER);
    }
    
    public function addToRepo()
    {
        $this->repo->trees[$this->getHash()] = $this;
    }
    
    public function getHash()
    {
        return hash('sha1', $this->export());
    }
    
    public function export()
    {
        $tree = '';
        
        foreach ($this->tree_data as $entry)
        {
            $tree = $tree . $entry['type'] . ' ' . $entry['name'] . "\0" . $entry['hash_bin'];
        }
        
        $length = strlen($tree);
        $tree = 'tree ' . $length . "\0" . $tree;
        
        return $tree;
    }
    
    public static function parse($tree)
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
                
                $hash_bin = substr($raw_entries, $i + 1, 20);
                $hash_hex = bin2hex($hash_bin);
                
                $file_entry = array('type' => $type_id, 'name' => $filename, 'hash' => $hash_hex);
                array_push($tree_data, $file_entry);
                
                $i = $i + 21; // Push $i past the hash
                $beginning_of_entry = $i;
            }
        }
        return $tree_data;
    }
    
    public static function newFromData($data)
    {
        $instance = new self();
        $instance->tree_data = self::parse($data);
    }
    
    public static function newRoot($rev_id, $log_id,)
    {
        $instance = new self();
        $namespaces = array_diff(MWNamespace::getCanonicalNamespaces(), $GLOBALS['wgGitAccessNSBlacklist']);
        foreach ($namespaces as $id => $name)
        {
            if ($id >= 0)
            {
                
            }
        }
        
        // ...
        
        return $instance;
    }
    
    public static function newFromSubpages($titles, $rev_id, $log_id)
    {
        
    }
    
    public static function newFromNamespace($rev_id, $log_id, $ns_id)
    {
        $dbw = wfGetDB(DB_MASTER);
        
        $sql = $dbw->selectSQLText(
            array('page', 'revision'),
            array(
                'is_archive' => '\'false\'',
                'page.page_id',
                'page.page_namespace',
                'rev_id' => 'MAX(revision.rev_id)'
            ),
            array(
                'rev_id <= ' . $rev_id,
                'page_namespace' => $ns_id
            ),
            __METHOD__,
            array(
                'GROUP BY' => array('\'false\'', 'page_id', 'page_namespace')
            ),
            array(
                'revision' => array('INNER JOIN', 'page_id = rev_page')
            )
        );
        $sql .= ' UNION ';
        $sql .= $dbw->selectSQLText(
            'archive',
            array(
                'is_archive' => '\'true\'',
                'page_id' => 'ar_page_id',
                'page_namespace' => 'ar_namespace',
                'rev_id' => 'MAX(ar_rev_id)'
            ),
            array(
                'ar_rev_id <= ' . $rev_id,
                'ar_namespace' => $ns_id
            ),
            __METHOD__,
            array(
                'GROUP BY' => array('\'true\'', 'ar_page_id', 'ar_namespace')
            )
        );
        
        $result = $dbw->query($sql);
        
        $tree_data = array();
        do
        {
            $row = $result->fetchRow();
            if ($row)
            {
                // Fetch Revision
                if ($row['is_archive'] === 'true')
                {
                    $ar_row = $dbw->selectRow(
                        'archive',
                        Revision::selectArchiveFields(),
                        array('ar_rev_id' => $row['rev_id'])
                    );
                    $revision = Revision::newFromArchiveRow($ar_row);
                }
                else
                {
                    $revision = Revision::newFromId($row['rev_id'], Revision::READ_LATEST);
                }
                
                $titleValue = self::getTitleAtRevision($revision, $log_id);
                $blob = new GitBlob($revision->getContent(Revision::RAW)->serialize());
                $blob->addToRepo();
                array_push(
                    $tree_data,
                    array(
                        'type' => self::T_NORMAL_FILE,
                        'name' => $titleValue->getDBKey(),
                        'hash_bin' => $blob->getHash(true)
                    )
                );
            }
        }
        while ($row);
        
        $instance = new self();
        $instance->tree_data = $tree_data;
        
        return $instance;
    }
    
    public static function getTitleAtRevision(Revision $revision, $log_id = null)
    {
        $dbw = wfGetDB(DB_MASTER);
        $conds = array(
            'log_page' => $revision->getPage(),
            'log_action' => 'move',
            'log_timestamp <= ' . $revision->getTimestamp(),
        );
        if ($log_id) array_push($conds, 'log_id <= ' . $log_id);
        
        $result = $dbw->selectRow(
            'logging',
            array(
                'log_id' => 'MAX(log_id)'
            ),
            $conds
        );
        if ($result->log_id)
        {
            $titleText = DatabaseLogEntry::newFromRow(
                $dbw->selectRow(
                    'logging',
                    '*',
                    'log_id=' . $result->log_id
                )
            )->getParameters()['4::target'];
            
            return MediaWikiServices::getInstance()->getTitleParser()->parseTitle($titleText, NS_MAIN);
        }
        else
        {
            return new TitleValue($revision->getTitle()->getNamespace(), $revision->getTitle()->getDBKey());
        }
    }
}

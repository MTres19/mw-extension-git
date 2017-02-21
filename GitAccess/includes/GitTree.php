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
 * 
 * @file
 */

/**
 * Class interfacing between Git tree objects and MediaWiki's pages.
 */
class GitTree extends AbstractGitObject
{
    public $tree_data;
    
    const T_NORMAL_FILE = 100644;
    const T_EXEC_FILE = 100755;
    const T_SYMLINK = 120000;
    const T_TREE = 40000;
    
    
    public function addToRepo()
    {
        $this->repo->trees[$this->getHash()] = &$this;
    }
    
    public function export()
    {
        $tree = '';
        
        foreach ($this->tree_data as $entry)
        {
            $tree = $tree . $entry['type'] . ' ' . $entry['name'] . "\0" . $entry['object']->getHash(true);
        }
        
        $length = strlen($tree);
        $tree = 'tree ' . $length . "\0" . $tree;
        
        return $tree;
    }
    
    /**
     * Organizes files into subtrees
     * Finds files with a forward slash in the name and builds a
     * directory structure.
     * 
     * @param int $ns_id The namespace id (e.g. NS_MAIN, NS_TALK, etc.) that this
     * tree represents or is in. Used to determine whether to attempt to process
     * the subpages.
     */
    public function processSubpages($ns_id)
    {
        if (!MWNamespace::hasSubpages($ns_id)) { return; }
        
        $subpages = array();
        foreach ($this->tree_data as $key => $entry)
        {
            if ($entry['type'] != self::T_NORMAL_FILE) { continue; }
            
            // Find the part before the first slash
            if(preg_match('~^(.[^\/]*)\/(.+)$~', $entry['name'], $matches) === 0)
            {
                continue;
            }
            
            /* Make sure there's an entry in the array of subpage directories
             * that matches the containing part.
             */
            if (!isset($subpages[$matches[1]])) { $subpages[$matches[1]] = array(); }
            $new_entry = array(
                'name' => $matches[2],
                'type' => self::T_NORMAL_FILE,
                'object' => &$entry['object'] // Blob doesn't change
            );
            array_push($subpages[$matches[1]], $new_entry); // Add the entry to the list files under the page
            
            unset($this->tree_data[$key]); // Remove the original entry from the main tree
        }
        
        foreach ($subpages as $name => $entry)
        {
            $subpage_tree = new self();
            $subpage_tree->tree_data = $entry;
            $subpage_tree->processSubpages($ns_id);
            //Illegal characters and capitalization passes...?
            $subpage_tree->addToRepo();
            $subpage_tree_entry = array(
                'name' => $name,
                'type' => self::T_TREE,
                'object' => &$subpage_tree
            );
            array_push($this->tree_data, $subpage_tree_entry);
        }
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
                
                $file_entry = array('type' => $type_id, 'name' => $filename, 'hash_bin' => $hash_bin);
                array_push($tree_data, $file_entry);
                
                $i = $i + 21; // Push $i past the hash
                $beginning_of_entry = $i;
            }
        }
        // Fetch the object in object form
        foreach ($tree_data as $key => $entry)
        {
            switch ($entry['type']) {
                case self::T_NORMAL_FILE:
                case self::T_EXEC_FILE:
                    $tree_data[$key]['object'] = &$this->repo->fetchBlob(bin2hex($entry['hash_bin']));
                    break;
                case self::T_TREE:
                    $tree_data[$key]['object'] = &$this->repo->fetchTree(bin2hex($entry['hash_bin']));
                    break;
                default:
                    // Panic/convulsions...? Who knows?
            }
        }
        
        return $tree_data;
    }
    
    public static function newFromData($data)
    {
        $instance = new self();
        $instance->tree_data = self::parse($data);
    }
    
    /**
     * Generate a new root GitTree
     * Creates a tree from the GitAccess_root namespace, then appends other namespaces
     * to it as directories.
     * 
     * @param int $rev_id The revision ID to build the tree at
     * @param int $log_id The log ID used for reference when building the tree.
     * @return GitTree The root tree
     */
    public static function newRoot($rev_id, $log_id)
    {
        $instance = self::newFromNamespace($rev_id, $log_id, NS_GITACCESS_ROOT);
        
        $namespaces = array_flip(MWNamespace::getCanonicalNamespaces());
        $namespaces = array_fill_keys(array_keys($namespaces), 1); // All namespaces included
        $namespaces = array_merge($namespaces, $GLOBALS['wgGitAccessNSIncluded']); // Un-include some namespaces
        /* Un-include dynamically generated namespaces.
         * Note that the Media folder is used to store files with GitAccess.
         * The File folder stores the description pages.
         */
        $namespaces['Media'] = false;
        $namespaces['Special'] = false;

        
        foreach ($namespaces as $name => $isIncluded)
        {
            if (!$isIncluded) { continue; }
            if ($name == 'File')
            {
                $media_tree = new self();
                $media_tree->tree_data = array();
            }
            
            if ($name == 'File')
            {
                $ns_tree = self::newFromNamespace(
                    $rev_id,
                    $log_id,
                    MWNamespace::getCanonicalIndex(strtolower($name)),
                    $media_tree
                );
            }
            else
            {
                $ns_tree = self::newFromNamespace(
                    $rev_id,
                    $log_id,
                    MWNamespace::getCanonicalIndex(strtolower($name))
                );
            }
            
            // Empty trees should not be included
            if ($ns_tree->tree_data)
            {
                $ns_tree->addToRepo();
                array_push(
                    $instance->tree_data,
                    array(
                        'type' => self::T_TREE,
                        'name' => $name ?: '(Main)',
                        'object' => &$instance
                    )
                );
            }
        }
        
        // Empty trees should not be included
        if ($media_tree->tree_data)
        {
            $media_tree->addToRepo();
            array_push(
                $instance->tree_data,
                array(
                    'type' => self::T_TREE,
                    'name' => 'Media',
                    'object' => &$media_tree
                )
            );
        }
        
        return $instance;
    }
    
    /**
     * Generates a new GitTree from a single namespace
     * 
     * @param int $rev_id The revision ID to build the tree at
     * @param int $log_id The log ID used for reference when building the tree.
     * @param int $ns_id The namespace ID to build the tree from
     * @param GitTree &$media_tree (optional) The GitTree used to store files. Populated when
     * $ns_id is NS_FILE.
     * @return GitTree The generated GitTree
     */
    public static function newFromNamespace($rev_id, $log_id, $ns_id, &$media_tree = null)
    {
        $dbw = wfGetDB(DB_MASTER);
        
        /* {{{ SQL stuff */
		$sqls = array();
        array_push($sqls, $dbw->selectSQLText(
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
        ));
        array_push($sqls, $dbw->selectSQLText(
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
        ));
		$sql = $dbw->unionQueries($sqls, false);
        $result = $dbw->query($sql);
        /* }}}  End SQL stuff*/
        
        $mimeTypesRepo = new Dflydev\ApacheMimeTypes\FlatRepository(
            $GLOBALS['IP'] . '/extensions/GitAccess/vendor/dflydev-apache-mimetypes/mime.types'
        );
        
        $instance = new self();
        $instance->tree_data = array();
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
                        '*',
                        array('ar_rev_id' => $row['rev_id'])
                    );
                    $revision = Revision::newFromArchiveRow($ar_row);
                }
                else
                {
                    $revision = Revision::newFromId($row['rev_id'], Revision::READ_LATEST);
                }
                
                $titleValue = self::getTitleAtRevision($revision, $log_id);
                
                if (self::pageExisted($titleValue, $log_id))
                {
                    $blob = GitBlob::newFromRaw($revision->getContent(Revision::RAW)->serialize());
                    $blob->addToRepo();
                    array_push(
                        $instance->tree_data,
                            array(
                            'type' => self::T_NORMAL_FILE,
                            'name' => $titleValue->getDBkey() . self::determineFileExt($titleValue, $revision),
                            'object' => &$blob
                        )
                    );
                    
                    unset($blob); // Avoid overwriting the reference on next iteration
                    
                    if ($ns_id == NS_FILE) { self::fetchFile($media_tree, $revision, $titleValue); }
                }
            }
        }
        while ($row);
        
        // Filter passes
        if ($ns_id != NS_FILE && $ns_id != NS_GITACCESS_ROOT) { $instance->processSubpages($ns_id); }
        
        return $instance;
    }
    
    /**
     * Fetches a file and adds it to the tree representing the Media  namespace
     * 
     * @param GitTree &$media_tree The GitTree used to add the file to
     * @param Revision $revision The revision of the page in the File namespace
     * @param TitleValue $title The time-dependent title of the revision (see GitTree::getTitleAtRevision())
     */
    public static function fetchFile(GitTree &$media_tree, Revision $revision, TitleValue $title)
    {
        /* Filenames in the filearchive table don't get updated when the page is moved.
         * Therefore, in order to build a proper list of files attached to the page,
         * we'll search the logging table for deletion entries and run DB queries galore.
         * That's what you get when the DB schema has grown organically over about a decade.
         */
        $dbw = wfGetDB(DB_MASTER);
        $filenames_result = $dbw->select(
            'logging',
            'log_title',
            array(
                'log_action' => 'delete',
                'log_action' => 'delete',
                'log_namespace' => NS_FILE,
                'log_page' => $revision->getPage()
            )
        );
        $file_names = array();
        do
        {
            $row = $filenames_result->fetchRow();
            if ($row)
            {
                array_push($file_names, $row['log_title']);
            }
        }
        while ($row);
        
        $filequeriesSQL = array();
        array_push($filequeriesSQL, $dbw->selectSQLText(
            'filearchive',
            array(
                'is_filearchive' => '\'true\'',
                'is_old' => '\'false\'',
                'img_name' => 'fa_name',
                'img_archive_name' => 'fa_archive_name',
                'img_fa_storage_key' => 'fa_storage_key',
                'img_media_type' => 'fa_media_type',
                'img_timestamp' => 'fa_timestamp'
            ),
            array(
                'fa_name IN (\''. implode('\',\'', $file_names) . '\')',
                'fa_timestamp <= ' . wfTimestamp(TS_MW, wfTimestamp(TS_UNIX, $revision->getTimestamp()) + 2)
            )
        )
        );
        array_push($filequeriesSQL, $dbw->selectSQLText(
            'oldimage',
            array(
                'is_filearchive' => '\'false\'',
                'is_old' => '\'true\'',
                'img_name' => 'oi_name',
                'img_archive_name' => 'oi_archive_name',
                'img_fa_storage_key' => 'NULL',
                'img_media_type' => 'oi_media_type',
                'img_timestamp' => 'oi_timestamp'
            ),
            array(
                'oi_name' => $revision->getTitle()->getDBkey(),
                'oi_timestamp <= ' . wfTimestamp(TS_MW, wfTimestamp(TS_UNIX, $revision->getTimestamp()) + 2)
            )
        )
        );
        array_push($filequeriesSQL, $dbw->selectSQLText(
            'image',
            array(
                'is_filearchive' => '\'false\'',
                'is_old' => '\'false\'',
                'img_name',
                'img_archive_name' => 'NULL',
                'img_fa_storage_key' => 'NULL',
                'img_media_type',
                'img_timestamp'
            ),
            array(
                'img_name' => $revision->getTitle()->getDBkey(),
                'img_timestamp <= ' . wfTimestamp(TS_MW, wfTimestamp(TS_UNIX, $revision->getTimestamp()) + 2)
            )
        )
        );
        $img_result = $dbw->query($dbw->unionQueries($filequeriesSQL, false));
        
        $latest_img = null;
        $current_max = 20031208000000; /* Semi-arbitrary. It's actually MW 1.1's
                                        * release date. Not that this extension
                                        * will probably work with a DB that old.
                                        */
        do
        {
            $row = $img_result->fetchRow();
            if ($row)
            {
                if ($row['img_timestamp'] > $current_max)
                {
                    $current_max = $row['img_timestamp'];
                    $latest_img = $row;
                }
            }
        }
        while ($row);
        
        if ($latest_img)
        {
            if ($latest_img['is_filearchive'] === 'true')
            {
                $img_path = $GLOBALS['wgDeletedDirectory']
                . '/'
                . substr($latest_img['img_fa_storage_key'], 0, 1) . '/'
                . substr($latest_img['img_fa_storage_key'], 1, 1) . '/'
                . substr($latest_img['img_fa_storage_key'], 2, 1) . '/'
                . $latest_img['img_fa_storage_key'];
            }
            elseif ($latest_img['is_old'] === 'true')
            {
                $img_name_hash = hash('md5', $latest_img['img_name']);
                $img_path = $GLOBALS['wgUploadDirectory']
                . '/archive/'
                . substr($img_name_hash, 0, 1) . '/'
                . substr($img_name_hash, 0, 2) . '/'
                . $latest_img['img_archive_name'];
            }
            else
            {
                $img_name_hash = hash('md5', $latest_img['img_name']);
                $img_path = $GLOBALS['wgUploadDirectory']
                . '/'
                . substr($img_name_hash, 0, 1) . '/'
                . substr($img_name_hash, 0, 2) . '/'
                . $latest_img['img_name'];
            }
            
            $blob = GitBlob::newFromRaw(file_get_contents($path));
            $blob->addToRepo();
            
            array_push(
                $media_tree->tree_data,
                array(
                    'type' => ($latest_img['img_media_type'] == MEDIATYPE_EXECUTABLE)
                                ? self::T_EXEC_FILE
                                : self::T_NORMAL_FILE,
                    'name' => $title->getDBkey(),
                    'object' => &$blob
                )
            );
        }
    }
    
    /**
     * Gets the actual name a page had at a point in history.
     * Revision::getTitle() always returns the current title of the page,
     * which causes big problems since it would change the hashes of Git trees.
     * This utility method searches the logging table to be sure the page wasn't moved
     * in the past.
     * 
     * @param Revision $revision The revision to fetch the title for
     * @param int $log_id (optional) The log_id to use in searching the logging table, for better accuracy
     * @return TitleValue The title of the page at the given revision
     */
    public static function getTitleAtRevision(Revision $revision, $log_id = null)
    {
        $dbw = wfGetDB(DB_MASTER);
        
        // Make page merge info available for querying
        $mergeLogsFiller = new FillMergeLogFieldsJob(Title::newMainPage(), array());
        $mergeLogsFiller->run();
        
        // Merge log stuff {{{
        $merge_dest_title = $revision->getTitle()->getDBkey();
        $merge_dest_ns = $revision->getTitle()->getNamespace();
        $previous_merge_result_row = null;
        do
        {
            $merge_result = $dbw->selectRow(
                'logging',
                array(
                    'log_id' => 'MIN(log_id)'
                ),
                array(
                    'log_merge_destination' => $merge_dest_title,
                    'log_merge_destination_namespace' => $merge_dest_ns,
                    'log_merge_mergepoint >= ' . $revision->getTimestamp()
                )
            );
            if ($merge_result->log_id)
            {
                // Fetch the whole row
                $merge_result_row = $dbw->selectRow('logging', '*', ['log_id' => $merge_result->log_id]);
                
                if ($revision->getTimestamp() >= $merge_result_row->log_timestamp)
                {
                    /* The merge found happened before the revision, so the destination
                     * of the merge must be the title of the page. Later we'll search to
                     * see whether the page was moved after merging.
                     * 
                     * This isn't technically necessary. We could just assume the revision's
                     * title is ambiguous and go through another iteration until it's discovered
                     * that the mergepoint was older than the revision, but that would be wasteful.
                     */
                    $method = 'SEARCH_MOVES_WITH_TITLE';
                    $title = $merge_result_row->log_merge_destination;
                    $title_ns = $merge_result_row->log_merge_destination_namespace;
                    break;
                }
                else
                {
                    /* The merge happened after the revision, so we don't know whether it
                     * used to be a revision of a different page. We'll look again to see
                     * whether there was an older merge that happened under a different title
                     * (i.e. the source of this merge might have been the destination of another.
                     */
                    $merge_dest_title = $merge_result_row->log_title;
                    $merge_dest_ns = $merge_result_row->log_namespace;
                    $previous_merge_result_row = $merge_result_row;
                    continue;
                }
            }
            elseif ($previous_merge_result_row)
            {
                /* No more merges were found where the revision was older than the mergepoint.
                 * Therefore, the previous merge log entry must show the true source of the revision.
                 */
                $method = 'SEARCH_MOVES_WITH_TITLE';
                $title = $previous_merge_result_row->log_merge_destination;
                $title_ns = $previous_merge_result_row->log_merge_destination_namespace;
            }
            else
            {
                /* No merge log entries were found in this iteration, and if there were a previous
                 * iteration, $previous_merge_result_row would not be null.
                 */
                $method = 'UNMERGED';
                break;
            }
        }
        while ($merge_result->log_id);
        // }}}
        
        $move_search_conds = array(
            // Account for timestamp differences between tables (ugh) ↓
            'log_timestamp <= ' . wfTimestamp(TS_MW, (wfTimestamp(TS_UNIX, $revision->getTimestamp()) + 2)),
            'log_type' => 'move'
        );
        switch ($method)
        {
            case 'UNMERGED':
                $move_search_conds['log_page'] = $revision->getPage();
                break;
            case 'SEARCH_MOVES_WITH_TITLE':
                $move_search_conds['log_title'] = $title;
                $move_search_conds['log_namespace'] = $title_ns;
                break;
        }
        
        $move_search_result = $dbw->selectRow('logging', ['log_id' => 'MAX(log_id)'], $move_search_conds);
        
        if ($move_search_result->log_id)
        {
            $titleText = DatabaseLogEntry::newFromRow(
                $dbw->selectRow(
                    'logging',
                    '*',
                    'log_id=' . $move_result->log_id
                )
            )->getParameters()['4::target'];
            
            return MediaWiki\MediaWikiServices::getInstance()->getTitleParser()->parseTitle($titleText, NS_MAIN);
        }
        elseif ($method == 'SEARCH_MOVES_WITH_TITLE')
        {
            return new TitleValue(
                $title,
                $title_ns
            );
        }
        else // i.e. no merge or move logs found
        {
            return $revision->getTitle()->getTitleValue();
        }
    }
    
    /**
     * Decides the file extension a file should have
     * This is usually based on the mimetype stored in the revision table,
     * but some pages like user CSS and JavaScript pages have their content
     * format fields based on the page name. It wouldn't make sense to have
     * User/MTres19/my_css.css.css, which is what you'd get without checking
     * for existing file extensions. Of course, this the file extension from
     * the title can't necessarily be relied on. File description pages might
     * end in ".png" but are actually wikitext. To guard against this, this
     * function checks to make sure the content format given by MediaWiki
     * matches the file extension.
     * 
     * @param TitleValue $title The title of page to find the file extension for
     * @param Revision $rev The revision to get the mimetype from if needed
     * @return string The file extension, including the dot, or an empty sting if
     * the page name already contains an extension.
     */
    public static function determineFileExt(TitleValue $title, Revision $rev)
    {
        /* Search the logging table for content model changes. User CSS/JavaScript
         * pages only store the content model in the page table, so when the content
         * model is changed that's the end of it and Revision::getContentFormat() is
         * inaccurate for older revisions.
         */
        $dbw = wfGetDB(DB_MASTER);
        $contentModelChangeParams = $dbw->selectField(
            'logging',
            'MAX(log_params)',
            array(
                'log_type' => 'contentmodel',
                'log_action' => 'change',
                // Account for timestamp differences between tables (ugh) ↓
                'log_timestamp <= ' . wfTimestamp(TS_MW, (wfTimestamp(TS_UNIX, $rev->getTimestamp()) + 2)),
                'log_page' => $rev->getPage()
            )
        );
        
        if ($contentModelChangeParams)
        {
            $dbContentModel = LogEntryBase::extractParams($contentModelChangeParams)['5::newmodel'];
            $dbContentFormat = ContentHandler::getForModelId($dbContentModel)->getDefaultFormat();
        }
        else
        {
            $dbContentFormat = $rev->getContentFormat();
        }
        
        $mimeTypesRepo = new Dflydev\ApacheMimeTypes\FlatRepository(
            $GLOBALS['IP'] . '/extensions/GitAccess/vendor/dflydev-apache-mimetypes/mime.types'
        );
        
        preg_match('~^.*\.(.[^\.]*)$~', $title->getDBkey(), $matches);
        $extFromTitle = !empty($matches[1]) ? $matches[1] : null;
        
        if ($extFromTitle && $mimeTypesRepo->findType($extFromTitle) === $dbContentFormat)
        {
            return '';
        }
        else
        {
            return '.' . $mimeTypesRepo->findExtensions($dbContentFormat)[0];
        }
    }
    
    /**
     * Figures out whether the page was deleted at the time
     * (Whether it should appear in the tree)
     * 
     * @param TitleValue $title The title of the page to check
     * @param int $log_id The most recent log ID for the commit referencing this tree
     * 
     * @todo Should support timestamp-only search. (Don't forget to account for timestamp
     * discrepencies.)
     */
    
    public static function pageExisted(TitleValue $title, $log_id)
    {
        $dbw = wfGetDB(DB_MASTER);
        $del_log_id = $dbw->selectField(
            'logging',
            'MAX(log_id)',
            array(
                'log_id <= ' . $log_id,
                'log_type' => 'delete',
                'log_namespace' => $title->getNamespace(),
                'log_title' => $title->getDBkey()
            )
        );
        
        $action = $dbw->selectField(
            'logging',
            'log_action',
            array('log_id' => $del_log_id)
        );
        if ($action == 'delete')
        {
            return false;
        }
        elseif ($action == 'restore')
        {
            return true;
        }
        else
        {
            return true;
        }
    }
}

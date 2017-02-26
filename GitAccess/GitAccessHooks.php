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
 
/**
 * Hooks for GitAccess extension
 * 
 * @file
 */

/**
 * Hooks for GitAccess extension
 */
class GitAccessHooks
{
    /**
     * Hook to create needed database tables.
     * 
     * Run by update.php.
     * 
     * @param DatabaseUpdater $dbUpdater The DatabaseUpdater object
     */
    public static function onLoadExtensionSchemaUpdates($dbUpdater = null)
    {
        if (!$dbUpdater->tableExists('git_hash'))
        {
            $dbUpdater->addExtensionTable(
                'git_hash',
                dirname(__FILE__) . '/sql/git_hash_schema_mysql.sql'
            );
        }
        
        if (!$dbUpdater->tableExists('git_edit_hash'))
        {
            $dbUpdater->addExtensionTable(
                'git_edit_hash',
                dirname(__FILE__) . '/sql/git_edit_hash_schema_mysql.sql'
            );
        }
        
        if (!$dbUpdater->tableExists('git_status_modify_hash'))
        {
            $dbUpdater->addExtensionTable(
                'git_status_modify_hash',
                dirname(__FILE__) . '/sql/git_status_modify_hash_schema_mysql.sql'
            );
        }
        
        if (!$dbUpdater->tableExists('git_tag'))
        {
            $dbUpdater->addExtensionTable(
                'git_tag',
                dirname(__FILE__) . '/sql/git_tag_schema_mysql.sql'
            );
        }
        
        $dbUpdater->addExtensionField(
            'logging',
            'log_merge_destination',
            dirname(__FILE__) . '/sql/patch-logging-merge-fields.sql'
        );
        
        return true;
    }
    
    /**
     * Function for hook ArticleMergeComplete. Updates the additional loggging
     * table fields with data from log_params so that it can be queried.
     * 
     * @param Title $targetTitle The source title of the merge. It doesn't
     * really matter for the job this adds to the queue.
     * @param Title $destTitle The destination title of the merge. It doesn't
     * really matter for the job this adds to the queue.
     */
    public static function onArticleMergeComplete($targetTitle, $destTitle)
    {
        $job = new FillMergeLogFieldsJob($destTitle, array());
        JobQueueGroup::singleton()->push($job);
    }
    
    /**
     * Checks to make sure a user doesn't overwrite the tags that GitAccess
     * needs to reserve.
     * 
     * @param string $tag The tag name
     * @param User $user The user attempting to create a tag. Unneeded and may be null.
     * @param Status &$status The Status object of this tag creation action.
     */
    public static function onChangeTagCanCreate($tag, $user, &$status)
    {
        if (preg_match('~^git-track-.[0-9a-fA-F]{39}$~', $tag) === 1)
        {
            $status->fatal('gitaccess-error-reservedchangetag');
        }
    }
    
    /**
     * Registers the change tags currently in use by GitAccess.
     * Used for both ListDefinedTags and ChangeTagsListActive.
     * 
     * @param array &$tags Array of valid tags
     */
    public static function onChangeTagRegistration(&$tags)
    {
        $dbw = wfGetDB(DB_MASTER);
        $result = $dbw->select(
            'git_commit_tracks',
            'associated_tag',
            array(),
            __METHOD__,
            array(
                'DISTINCT' => true
            )
        );
        do
        {
            $row = $result->fetchRow()
            if ($row)
            {
                array_push($tags, $row['associated_tag']);
            }
        }
        while ($row);
    }
    
    /**
     * Records the archive name (i.e. YYYYMMDDHHMMSS!FileName)
     * of file revisions that are deleted separate from the entire
     * page. This way GitAccess doesn't have to rely on matching
     * the deletedrevision system message to know whether or not
     * the description page was deleted too.
     * 
     * @param LocalFile $file The name of the file that was deleted (Current version,
     * not the deleted version)
     * @param string $oldimage The archive name of the deleted file.
     * (filearchive.fa_archive_name)
     * @param WikiFilePage $article The file description page
     * @param User $user The user that performed the file deletion
     * @param string $reason The given deletion reason
     */
    public static function onFileDeleteComplete($file, $oldimage, $article, $user, $reason)
    {
        if ($oldimage === '') { return; } // Entire page was deleted
        
        $dbw = wfGetDB(DB_MASTER);
        $log_id = $dbw->selectField(
            'logging',
            'MAX(log_id)',
            array(
                'log_action' => 'delete',
                'log_page' => $article->getTitle()->getArticleId()
            )
        );
        // Work around T155064, and be nice in case someone else uses this field
        $params = unserialize($dbw->selectField('logging', 'log_params', ['log_id' => $log_id]));
        $params['4::deletedname'] = $oldimage;
        $dbw->update(
            'logging',
            ['log_params' => serialize($params)],
            ['log_id' => $log_id]
        );
    }
    
    /**
     * Store the restored revisions in log_params so that the latest shown
     * revision in Git can be whatever the newest restored revision was.
     * 
     * @param Title $title The title of the restored page
     * @param Revision $revision The restored revision
     * @param string|int $oldPageID The page ID from the archive table.
     * Sometimes MediaWiki will be able to restore a page with its original ID,
     * but not always.
     */
    public static function onArticleRevisionUndeleted(Title $title, Revision $revision, $oldPageID)
    {
        $dbw = wfGetDB(DB_MASTER);
        static $rev_ids = array();
        static $undelete_log_id = $dbw->selectField(
            'logging',
            'MAX(log_id)',
            array(
                'log_page' => $title->getArticleId(),
                'log_type' => 'delete',
                'log_action' => 'restore'
            )
        );
        $rev_ids[] = $revision->getId()
        $log_params = unserialize($dbw->selectField('logging', 'log_params', ['log_id' => $undelete_log_id]));
        $log_params['4::restoredrevs'] = $rev_ids;
        $dbw->update(
            'logging',
            ['log_params' => serialize($log_params)],
            ['log_id' => $undelete_log_id]
        );
    }
}       

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
 
class GitRepository
{
    public $blobs;
    public $trees;
    public $commits;
    public $tags;
    public $HEAD;
    public $HEAD_log_id;
    public $HEAD_rev_id;
    
    public static $instance = null;
    
    public function __construct()
    {
        $this->blobs = array();
        $this->trees = array();
        $this->commits = array();
        $this->tags = array();
    }
    
    public function getHead()
    {
        if (!$this->commits)
        {
            $this->HEAD = null;
        }
        
        else
        {
            foreach ($commits as $commit)
            {
                if ($commit->is_head)
                {
                    $this->HEAD = $commit->commit_hash;
                }
            }
        }
    }
    
    public function setHeadRevId()
    {
        /* For each parent commit, check if there are any revision IDs.
         * If there are in the first level of parents, save them and exit the loop.
         * Otherwise, make a new array of commits to try from the parents of the others
         * and run the loop again.
         */
        $currentCommitParents = array($this->HEAD);
        while ($currentCommitParents)
        {
            $revIds = array();
            foreach ($currentCommitParents as $currentCommitParent)
            {
                if ($this->commits[$currentCommitParent]->linked_rev_ids)
                {
                    $revIds = array_merge($revIds, $this->commits[$currentCommitParent]->linked_rev_ids);
                }
            }
            
            if ($revIds)
            {
                // Save the biggest rev_id and exit the do-while loop
                $this->HEAD_rev_id = max($revIds);
                break;
            }
            
            else
            {
                // Generate a new set of parent hashes to check
                $newCommitParents = array();
                foreach ($currentCommitParents as $currentCommitParent)
                {
                    $newCommitParents = array_merge(
                        $newCommitParents,
                        $this->commits[$currentCommitParent]->parent_hashes
                    );
                }
                
                $currentCommitParents = $newCommitParents;
            }
        }
        
        /* If Aliases.xml is attached to an older commit, its latest 
         * revision might not be found to be the HEAD_rev_id since 
         * setHeadRevId() searches the newest commits first.
         */
        
        $aliases_rev_latest = Title::makeTitle(NS_GITACCESS_ROOT, 'Aliases')->getLatestRevID();
        
        $this->HEAD_rev_id = ($aliases_rev_latest > $this->HEAD_rev_id) 
                                ? $aliases_rev_latest
                                : $this->HEAD-rev_id;
        
        /* If no revisions could be found in this object, then the oldest
         * revision not in this object or the journal must be revision 1.
         * Since revision 1 should be included in database queries, set
         * the HEAD revision to 0.
         */
        if (!$this->HEAD_rev_id)
        {
            $this->HEAD_rev_id = 0;
        }
    }
    
    // See comments in setHeadRevId() for how this works
    public function setHeadLogId()
    {
        $currentCommitParents = array($this->HEAD);
        while ($currentCommitParents)
        {
            $logIds = array();
            foreach ($currentCommitParents as $currentCommitParent)
            {
                if ($this->commits[$currentCommitParent]->linked_log_ids)
                {
                    $logIds = array_merge($logIds, $this->commits[$currentCommitParent]->linked_log_ids);
                }
            }
            
            if ($logIds)
            {
                $this->HEAD_log_id = max($logIds);
                break;
            }
            
            else
            {
                // Generate a new set of parent hashes to check
                $newCommitParents = array();
                foreach ($currentCommitParents as $currentCommitParent)
                {
                    $newCommitParents = array_merge(
                        $newCommitParents,
                        $this->commits[$currentCommitParent]->parent_hashes
                    );
                }
                
                $currentCommitParents = $newCommitParents;
            }
        }
        
        /* If no log entries could be found in this object, then the oldest
         * log entry not in this object or the journal must be entry 1.
         * Since entry 1 should be included in database queries, set
         * the HEAD log entry to 0.
         */
        if (!$this->HEAD_log_id)
        {
            $this->HEAD_log_id = 0;
        }
    }
    
    // WARNING: populateFromJournal() MUST have been called already
    // NOTE: This function also takes care of populating this object.
    // You do not need to call populateFromJournal() again.
    public function populateJournalFromHistory()
    {
        $this->getHead();
        $this->setHeadRevId();
        $this->setHeadLogId();
        $dbw = wfGetDB(DB_MASTER);
        
		$sqls = array();
        $sqls[] = $dbw->selectSQLText(
            'revision',
            array(
                'rev_id' => 'rev_id',
                'log_id' => 'NULL',
                'action_timestamp' => 'rev_timestamp'
            ),
            'rev_id > ' . $this->HEAD_rev_id
        );
        $sqls[] = $dbw->selectSQLText(
            'archive',
            array(
                'rev_id' => 'ar_rev_id',
                'log_id' => 'NULL',
                'action_timestamp' => 'rev_timestamp'
            ),
            'ar_rev_id > ' . $this->HEAD_rev_id
        );
        $sqls[] = $dbw->selectSQLText(
            'logging',
            array(
                'rev_id' => 'NULL',
                'log_id' => 'log_id',
                'action_timestamp' => 'log_timestamp'
            ),
            'log_id > ' . $this->HEAD_log_id . 'AND (log_type = \'delete\' OR log_type = \'move\' OR log_type = \'upload\')',
            __METHOD__,
            array(
                'ORDER BY' => array(
                    'action_timestamp ASC',
                    'log_id ASC',
                    'rev_id ASC'
                )
            )
        );
        
		$sql = $dbw->unionQueries($sqls, false);
        $result = $dbw->query($sql);
        
        /* In order to generate a tree and commit, the GitTree class
         * needs to know the most current (relevant) log id at the time
         * of the revision. (Or vice versa)
         */
        $previous_log_id = $this->HEAD_log_id;
        $previous_rev_id = $this->HEAD_rev_id;
        do
        {
            $row = $result->fetchRow();
            if ($row)
            {
                if ($row['rev_id'])
                {
                    $previous_rev_id = $row['rev_id'];
                    $commit = GitCommit::newFromRevId($row['rev_id'], $previous_log_id, $this);
                }
                elseif ($row['log_id'])
                {
                    $previous_log_id = $row['log_id'];
                    $commit = GitCommit::newFromLogId($row['log_id'], $previous_rev_id, $this);
                }
                
                $commit->addToRepo();
                $commit->journalize();
            }
            
            else
            {
                // Since the top revision and log entry have been reached, HEAD can move forward.
                $this->commits[$this->HEAD]->is_head = false;
                $this->commits[$this->HEAD]->journalize();
                $commit->is_head = true; // FYI $commit is the last commit made in the if statement above.
                $commit->journalize();
                
                $this->setHeadLogId();
                $this->setHeadRevId();
            }
        }
        while ($row);
    }
    
    public function populateFromJournal()
    {
        $dbw = wfGetDB(DB_MASTER);
        $result = $dbw->select('git_hash', 'commit_hash');
        do
        {
            $row = $result->fetchRow();
            if ($row)
            {
                $this->commits[$row['commit_hash']] = GitCommit::newFromHashJournal($row['commit_hash'], $this);
            }
        }
        while ($row);
    }
    
    public static function &singleton()
    {
        if (!self::$instance)
        {
            self::$instance = new self(wfGetDB(DB_MASTER));
        }
        return self::$instance;
    }
}


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

// Don't reveal path when PHP fails to extend class
if (!defined('MEDIAWIKI'))
{
    die('Not a valid entry point.');
}

/**
 * Populates the log_merge_* fields in the logging table from the
 * log_params column. This makes it much easier to query for history
 * merges.
 */
class FillMergeLogFieldsJob extends Job
{
    public function __construct($title, $params)
    {
        parent::__construct('fillMergeLogFields', $title, $params);
    }
    
    public function run()
    {
        $dbw = wfGetDB(DB_MASTER);
        
        // Make page merge info available for querying
        $incomplete_merge_columns_result = $dbw->select(
            'logging',
            array(
                'log_id',
                'log_params'
            ),
            array(
                'log_type' => 'merge',
                '(log_merge_destination IS NULL OR log_merge_destination_namespace IS NULL OR log_merge_mergepoint IS NULL)'
            )
        );
        
        do
        {
            $row = $incomplete_merge_columns_result->fetchRow();
            if ($row)
            {
                $logEntry = DatabaseLogEntry::newFromRow($row);
                $destTitle = MediaWiki\MediaWikiServices::getInstance()->getTitleParser->parseTitle($logEntry->getParameters['4::dest']);
                $dbw->update(
                    'logging',
                    array(
                        'log_merge_destination' => $destTitle->getDBkey(),
                        'log_merge_destination_namespace' => $destTitle->getNamespace(),
                        'log_merge_mergepoint' => $logEntry->getParameters()['5::mergepoint']
                    ),
                    array(
                        'log_id' => $row['log_id']
                    )
                );
            }
        }
        while ($row);
        
        return true;
    }
}

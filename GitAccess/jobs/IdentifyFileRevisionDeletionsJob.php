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
 * Populates the log_params field for individual file deletions
 * to mark them as separate from deletions of the entire page.
 * 
 * Currently not inserted into the job queue at all, but simply
 * run from GitTree.php. The FileDeleteComplete hook is better.
 */
class IdentifyFileRevisionDeletionsJob
{
    public function __construct($title, $params)
    {
        parent::__construct('identifyFileRevisionDeletions', $title, $params);
    }
    
    public function run()
    {
        $dbw = wfGetDB(DB_MASTER);
        
        $toDo = $dbw->select(
            'logging',
            array(
                'log_id',
                'log_comment',
                'log_params'
            ),
            array(
                'log_type' => 'delete',
                'log_action' => 'delete',
                'log_namespace' => NS_FILE,
                'log_params NOT' . $dbw->buildLike($dbw->anyString(), '4::deletedname', $dbw->anyString())
            )
        );
        
        $pattern = '^';
        /* Luckily we can avoid matching the most common colon-separator (i.e. ":") since it's
         * disallowed in file names. Page names are exempt but any attempt through Special:Upload
         * will always change the : to a -. This greatly reduces the chances of a ": " pattern in
         * the comment messing up the regex.
         */
        $pattern .= wfMessage('deletedrevision', '(.[0-9]{13}!.[^:]+)')->inContentLanguage()->text();
        
        do
        {
            $row = $toDo->fetchRow()
            if ($row)
            {
                if (strpos(
                    $row['log_comment'],
                    wfMessage('colon-separator')->inContentLanguage()->text())
                    !== false
                )
                {
                    $pattern_final = $pattern . wfMessage('colon-separator')->inContentLanguage()->text();
                }
                
                else
                {
                    $pattern_final = $pattern;
                }
                
                if (preg_match('~' . $pattern_final . '~', $row['log_comment'], $matches) === 1)
                {
                    // Be nice in case someone else uses this field
                    $params = unserialize($row['log_params']);
                    $params['4::deletedname'] = $matches[1];
                    $dbw->update(
                        'logging',
                        ['log_params' => serialize($params)]
                        ['log_id' => $log_id]
                    );
                }
            }
        }
        while ($row);
    }
}

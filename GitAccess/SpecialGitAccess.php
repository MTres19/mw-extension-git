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
 
/**
 * Special page for for GitAccess extension.
 * 
 * @file
 */

/**
 * This class provides a special page for accessing content
 * with Git. It provides an information page but delegates
 * most of the logic and communication to the GitRepository
 * GitClientCommunication classes.
 */
class SpecialGitAccess extends SpecialPage
{
    protected $output;
    protected $request;
    protected $response;
    
    /**
     * Constructor.
     */
    public function __construct()
    {
        parent::__construct("GitAccess", "gitaccess"); // Sysops only
        $this->output = $this->getOutput();
        $this->request = $this->getRequest();
        $this->response = $this->request->response();
    }
    
    public function execute($subpath)
    {
        $request_service = $this->request->getText("service"); // NOTE: To get this value, URL rewriting is required!
        $path_objects = explode('/', $subpath); // Put subpath into an array for easy access
        
        if (empty($subpath) && empty($request_service)) // Show information page
        {
            $this->showInfoPage();
        }
        
        elseif (!empty($request_service) || $path_objects[1] == 'git-upload-pack' || $path_objects[1] == 'git-receive-pack')
        {
            $this->executeGitService($path_objects, $request_service);
        }
        
        else
        {
            $this->showDumbHttpPage();
        }
    }
    
    /**
     * Shows the information page.
     */
    public function showInfoPage()
    {
        global $wgCanonicalServer, $wgScriptPath, $wgGitAccessRepoName;
        
        $this->output->setPageTitle($this->msg("gitaccess"));
        $this->output->addWikiText($this->msg("gitaccess-desc"));
        $this->output->addWikiText($this->msg("gitaccess-specialpagehome-info"));
        $this->output->addWikiText("<code><nowiki>git clone " . $wgCanonicalServer . $wgScriptPath . '/index.php?title=Special:GitAccess/' . $wgGitAccessRepoName . '.git</nowiki></code>');
        
        // Check permissions
        if (!$this->getUser()->isAllowed("gitaccess"))
        {
            throw new PermissionsError("gitaccess");
        }
        
        if (wfReadOnly())
        {
            $this->output->addWikiText($this->msg("gitaccess-specialpagehome-readonly"));
        }
        
        if ($this->getUser()->isBlocked())
        {
            throw new UserBlockedError($this->getUser()->mBlock);
        }
    }
    
    /**
     * Determines the type of Git request and executes it.
     * 
     * @param array $path The subpath passed to execute(),
     * split by a '/'
     * @param string $request_service The 'service' parameter
     * of the query string (if set). Only used for ref discovery.
     */
    public function executeGitService($path, $request_service)
    {
        global $wgGitAccessRepoName;
        
        /* This provides opportunity for other repository export options.
         * 
         * In the future, $path[0], which contains the repo name, might contain
         * something other than $wgGitAccessRepoName, like a special repository
         * that only includes a single wiki namespace, or the like.
         */
        $fullRepoName = $wgGitAccessRepoName . ".git";
        
        switch ($path[0])
        {
            case $fullRepoName:
                $method = "FULL_WIKI";
                break;
            default:
                $this->response->header("HTTP/1.1 404 Not Found");
                $this->output->setPageTitle("404");
                $this->output->addWikiText($this->msg("gitaccess-error-invalidrepo"));
        }
        
        // Determine request type
        if ($path[1] === 'info' && $path[2] === 'refs' && $request_service === 'git-upload-pack')
        {
            $request_type = GitClientCommunication::TYPE_REF_DISCOVERY_UPLOAD;
        }
        elseif ($path[1] === 'info' && $path[2] === 'refs' && $request_service === 'git-receive-pack')
        {
            $request_type = GitClientCommunication::TYPE_REF_DISCOVERY_RECEIVE;
        }
        elseif ($path[1] === 'git-upload-pack')
        {
            $request_type = GitClientCommunication::TYPE_UPLOAD_PACK;
        }
        elseif ($path[1] === 'git-receive-pack')
        {
            $request_type = GitClientCommunication::TYPE_RECEIVE_PACK;
        }
        else
        {
            $this->showDumbHttpPage();
            return;
        }
        
        switch ($method)
        {
            case "FULL_WIKI":
                $communication = new GitClientCommunication($this->output, $this->request, $this->response, $path, $request_type);
                
                if ($communication->auth() == true) // Verify user, don't do anything on failure (auth() handles that)
                {
                    if ($request_service == "git-upload-pack")
                    {
                        echo "git-upload-pack";
                    }
                    
                    elseif ($request_service == "git-receive-pack")
                    {
                        echo "git-receive-pack";
                    }
                }
                
                break;
        }
    }
    
    /**
     * Shows an error page when attempting to incorrectly access
     * the repository
     */
    public function showDumbHttpPage()
    {
        $this->response->header("HTTP/1.1 400 Bad Request");
        $this->output->setPageTitle($this->msg("gitaccess"));
        $this->output->addWikiText($this->msg("gitaccess-error-dumbhttpaccess"));
    }
    
    /**
     * Overloaded function to show that this may perform database
     * writes.
     */
    public function doesWrites()
    {
        return true;
    }
    
    
}

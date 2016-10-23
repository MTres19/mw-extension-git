<?php

class SpecialGitAccess extends SpecialPage
{
    protected $output;
    protected $request;
    protected $response;
    
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
        
        if (empty($subpath) && empty($request_service)) // Show information page
        {
            $this->showInfoPage();
        }
        
        else if (!empty($subpath) && !empty($request_service)) // Generate git repo
        {
            // Put subpath into an array for easy access
            $token = strtok($subpath, "/");
            $path_objects = array();
            while ($token)
            {
                array_push($path_objects, $token);
                $token = strtok("/");
            }
            
            $this->executeGitService($path_objects, $request_service);
        }
        
        else
        {
            $this->showDumbHttpPage();
        }
    }
    
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
    
    public function executeGitService($path, $request_service)
    {
        global $wgGitAccessRepoName;
        
        /* This provides opportunity for other repository export options.
         * 
         * In the future, $path[0], which contains the repo name, might contain
         * something other than $wgGitAccessRepoName, like a special repository
         * that only includes a single wiki namespace, or the like.
         */
        $testVar = $wgGitAccessRepoName . ".git";
        
        switch ($path[0])
        {
            case $testVar:
                $method = "FULL_WIKI";
                break;
            default:
                $this->response->header("HTTP/1.1 404 Not Found");
                $this->output->setPageTitle("404");
                $this->output->addWikiText($this->msg("gitaccess-error-invalidrepo"));
        }
        
        switch ($method)
        {
            case "FULL_WIKI":
                $communication = new GitClientCommunication($this->output, $this->request, $this->response, $path);
                
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
    
    public function showDumbHttpPage()
    {
        $this->response->header("HTTP/1.1 400 Bad Request");
        $this->output->setPageTitle($this->msg("gitaccess"));
        $this->output->addWikiText($this->msg("gitaccess-error-dumbhttpaccess"));
    }
    
    public function doesWrites()
    {
        return true; // Overload class to show that this may perform database writes
    }
    
    
}



?>

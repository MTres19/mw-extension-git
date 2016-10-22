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
            $this->executeGitService($subpath, $request_service);
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
    
    public function executeGitService($subpath, $request_service)
    {
        $this->output->disable(); // Take over output
        $communication = new GitClientCommunication($this->request, $this->response, $subpath);
        
        if ($communication->auth() == true) // Verify user, don't do anything on failure (auth() handles that)
        {
            // Put subpath into an array for easy access
            $token = strtok($subpath, "/");
            $path_objects = array();
            while ($token)
            {
                $token = strtok("/");
                array_push($path_objects, $token);
            }
            
            $repo = new GitRepository($path_objects);
            
            if ($request_service == "git-upload-pack")
            {
                echo "git-upload-pack";
            }
            
            elseif ($request_service == "git-receive-pack")
            {
                echo "git-receive-pack";
            }
        }
    }
    
    public function showDumbHttpPage()
    {
            $this->output->setPageTitle($this->msg("gitaccess"));
            $this->output->addWikiText($this->msg("gitaccess-error-dumbhttpaccess"));
    }
    
    public function doesWrites()
    {
        return true; // Overload class to show that this may perform database writes
    }
    
    
}



?>

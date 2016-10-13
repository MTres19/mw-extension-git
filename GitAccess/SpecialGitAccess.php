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
        $this->response = $request->response();
    }
    
    public function execute($subpath)
    {
        if (!isset($subpath) && !isset($this->request->getVal("service"))) // Show information page
        {
            $output->setPageTitle($this->msg("gitaccess"));
            $output->addWikiText($this->msg("gitaccess-desc"));
            $output->addWikiText($this->msg("gitaccess-specialpagehome-loggedin-info"));
            
            // Check permissions
            if (!$this->getUser()->isAllowed("gitaccess"))
            {
                throw new PermissionsError("gitaccess");
            }
            
            if (wfReadOnly())
            {
                throw new ReadOnlyError;
            }
            
            if ($this->getUser()->isBlocked())
            {
                throw new UserBlockedError($this->getUser()->mBlock);
            }
        }
        
        else if ($subpath && isset($this->request->getVal("service"))) // Generate git repo
        {
            $output->disable(); // Take over output
            
            $token = strtok($subpath, "/");
            $path_objects = array();
            while ($token)
            {
                $token = strtok("/");
                array_push($path_objects, $token);
            }
            
            $repo = new GitRepository($path_objects);
            
            if ($this->request->getVal("service") = "git-upload-pack")
            {
            
            }
            
            else if ($this->request->getVal("service") = "git-receive-pack")
            {
            
            }
        }
        
        else
        {
            $output->setPageTitle($this->msg("gitaccess"));
            $output->addWikiText($this->msg("gitaccess-error-dumbhttpaccess"));
        }
    }
    
    public function doesWrites()
    {
        return true; // Overload class to show that this may perform database writes
    }
    
    protected function auth()
    {
        $user = User::newFromName($_SERVER["PHP_AUTH_USER"]);
        
        if ($user->getID() = 0)
        {
            $response->header('WWW-Authenticate: Basic realm="MediaWiki"');
            $response->header("HTTP/1.1 401 Unauthorized");
        }
        else
        {
            if ("USERNAME AND PASSWORD MATCH" && $user->isAllowed("gitaccess"))
            {
                return true;
            }
            elseif ("USERNAME AND PASSWORD MATCH" && !$user->isAllowed("gitaccess"))
            {
                // echo permissions error or something
                return false;
            }
            elseif ("USERNAME AND PASSWORD DON'T MATCH")
            {
                // echo wrong password message or something
                return false;
            }
            else
            {
                return false;
            }
        }
    }
}



?>

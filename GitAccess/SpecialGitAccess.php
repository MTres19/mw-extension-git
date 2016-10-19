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
        $this->output->setPageTitle($this->msg("gitaccess"));
        $this->output->addWikiText($this->msg("gitaccess-desc"));
        $this->output->addWikiText($this->msg("gitaccess-specialpagehome-info"));
        
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
        
        if ($this->auth() == true) // Verify user, don't do anything on failure (auth() handles that)
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
                echo "Hello world";
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
    
    protected function auth()
    {
        // Auth
        $authManagerSingleton = MediaWiki\Auth\AuthManager::singleton();
        $user = User::newFromName($_SERVER["PHP_AUTH_USER"]);
        
        if (empty($user) || empty($_SERVER["PHP_AUTH_PW"])) /* No username sent */
        {
            $this->response->header('WWW-Authenticate: Basic realm="MediaWiki"');
            $this->response->header("HTTP/1.1 401 Unauthorized");
        }
        else
        {
            /* Create AuthenticationRequest---add username and password to object using loadRequestsFromSubmission()
             * 
             * The User parameter is needed because there is no session data provided by the git client.
             */
            $authenticationRequest = MediaWiki\Auth\AuthenticationRequest::loadRequestsFromSubmission(
                $authManagerSingleton->getAuthenticationRequests($authManagerSingleton::ACTION_LOGIN, $user),
                [
                    "username" => $_SERVER["PHP_AUTH_USER"],
                    "password" => $_SERVER["PHP_AUTH_PW"],
                ]
            );
            
            // Check password
            $authResult = $authManagerSingleton->beginAuthentication($authenticationRequest, ':null');
            
            if ($authResult->status == $authResult::PASS && $user->isAllowed("gitaccess"))
            {
                return true;
            }
            elseif ($authResult->status == $authResult::PASS && !$user->isAllowed("gitaccess"))
            {
                $this->response->header('WWW-Authenticate: Basic realm="MediaWiki"');
                $this->response->header("HTTP/1.1 401 Unauthorized");
                
                echo "Sorry, accessing this wiki with Git requires permissions which you do not have.";
                return false;
            }
            elseif ($authResult->status == $authResult::FAIL)
            {
                $this->response->header('WWW-Authenticate: Basic realm="MediaWiki"');
                $this->response->header("HTTP/1.1 401 Unauthorized");
                
                echo "Invalid username or password.";
                return false;
            }
            else
            {
                $this->response->header('WWW-Authenticate: Basic realm="MediaWiki"');
                $this->response->header("HTTP/1.1 401 Unauthorized");
                
                echo "Unknown authentication error.";
                return false;
            }
        }
    }
}



?>

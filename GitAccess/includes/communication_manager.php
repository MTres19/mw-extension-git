<?php

class GitClientCommunication
{
    protected $output;
    protected $requst;
    protected $response;
    protected $path;
    
    public function __construct(&$output, &$request, &$response, $path)
    {
        $this->output = $output;
        $this->request = $request;
        $this->response = $response;
        $this->path = $path;
        
        $this->output->disable(); // Take over output
    }
    
    public function auth()
    {
        // Auth
        $authManagerSingleton = MediaWiki\Auth\AuthManager::singleton();
        $user = User::newFromName($_SERVER["PHP_AUTH_USER"]);
        
        if (empty($user) || empty($_SERVER["PHP_AUTH_PW"])) /* Missing username or password */
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
                $this->response->header("HTTP/1.1 403 Forbidden");
                
                echo "Sorry, accessing this wiki with Git requires permissions which you do not have." . PHP_EOL;
                return false;
            }
            elseif ($authResult->status == $authResult::FAIL)
            {
                $this->response->header('WWW-Authenticate: Basic realm="MediaWiki"');
                $this->response->header("HTTP/1.1 401 Unauthorized");
                
                return false;
            }
            else
            {
                $this->response->header('WWW-Authenticate: Basic realm="MediaWiki"');
                $this->response->header("HTTP/1.1 501 Not Implemented");
                
                echo "Your request cannot be processed as authentication requires further information." . PHP_EOL;
                return false;
            }
        }
    }
    
}

?>

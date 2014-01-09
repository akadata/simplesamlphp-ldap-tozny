<?php

/**
 * Example external authentication source.
 *
 * This class is an example authentication source which is designed to
 * hook into an external authentication system.
 *
 * To adapt this to your own web site, you should:
 * 1. Create your own module directory.
 * 2. Add a file "default-enable" to that directory.
 * 3. Copy this file and modules/exampleauth/www/resume.php to their corresponding
 *    location in the new module.
 * 4. Replace all occurrences of "exampleauth" in this file and in resume.php with the name of your module.
 * 5. Adapt the getUser()-function, the authenticate()-function and the logout()-function to your site.
 * 6. Add an entry in config/authsources.php referencing your module. E.g.:
 *        'myauth' => array(
 *            '<mymodule>:External',
 *        ),
 *
 * @package simpleSAMLphp
 * @version $Id$
 */


/**
 * LDAP authentication source.
 *
 * See the ldap-entry in config-templates/authsources.php for information about
 * configuration of this authentication source.
 *
 * This class is based on www/auth/login.php.
 *
 * @package simpleSAMLphp
 * @version $Id$
 */
class sspmod_ldapSeqrd_Auth_Source_LDAPSeqrd extends SimpleSAML_Auth_Source {

	/**
	 * A LDAP configuration object.
	 */
	private $ldapConfig;


	/**
	 * Constructor for this authentication source.
	 *
	 * @param array $info  Information about this authentication source.
	 * @param array $config  Configuration.
	 */
	public function __construct($info, $config) {
		assert('is_array($info)');
		assert('is_array($config)');

		/* Call the parent constructor first, as required by the interface. */
		parent::__construct($info, $config);

        $this->realm_key_id = $config['realm_key_id'];
        $this->realm_secret_key = $config['realm_secret_key'];
        $this->api_url = $config['api_url'];

        require_once $config['seqrd_import']. "/SeqrdRemoteUserAPI.php";
        require_once $config['seqrd_import']. "/SeqrdRemoteRealmAPI.php";

        set_include_path(get_include_path() . PATH_SEPARATOR . $config['tiqr_directory']);

        require_once "Tiqr/OATH/OCRAWrapper.php";

		$this->ldapConfig = new sspmod_ldap_ConfigHelper($config,
			'Authentication source ' . var_export($this->authId, TRUE));
	}


	/**
	 * Attempt to log in using the given username and password.
	 *
	 * @param string $username  The username the user wrote.
	 * @param string $password  The password the user wrote.
	 * param array $sasl_arg  Associative array of SASL options
	 * @return array  Associative array with the users attributes.
	 */
	protected function login($username, $password, array $sasl_args = NULL) {
		assert('is_string($username)');
		assert('is_string($password)');

		$attributes = $this->ldapConfig->login($username, $password, $sasl_args);

        return $attributes;
	}

	/**
	 * This function is called when the user starts a logout operation, for example
	 * by logging out of a SP that supports single logout.
	 *
	 * @param array &$state  The logout state array.
	 */
	public function logout(&$state) {
		assert('is_array($state)');

		if (!session_id()) {
			/* session_start not called before. Do it here. */
			session_start();
		}

        session_destroy();

		/*
		 * If we need to do a redirect to a different page, we could do this
		 * here, but in this example we don't need to do this.
		 */
	}



	/**
	 * Retrieve attributes for the user.
	 *
	 * @return array|NULL  The user's attributes, or NULL if the user isn't authenticated.
	 */
	private function getUser() {

		/*
		 * In this example we assume that the attributes are
		 * stored in the users PHP session, but this could be replaced
		 * with anything.
		 */

		if (!session_id()) {
			/* session_start not called before. Do it here. */
			session_start();
		}

		if (!isset($_SESSION['uid'])) {
			/* The user isn't authenticated. */
			return NULL;
		}

		/*
		 * Find the attributes for the user.
		 * Note that all attributes in simpleSAMLphp are multivalued, so we need
		 * to store them as arrays.
		 */

		$attributes = array(
			'uid' => array($_SESSION['uid']),
		);
        if(isset($_SESSION['user_meta'])) {
            foreach ($_SESSION['user_meta'] as $key => $val) {
                if (in_array($key, ['user_id', 'return', 'status_code'])) {
                    continue;
                }
                if ($key == 'meta') {
                    if (is_array($val)) {
                        foreach ($val as $k => $v) {
                            $attributes['meta_'.$k] = array($v);
                        } 
                    }                   
                    continue;
                }
                $attributes[$key] = array ($val);
            }
        }


		return $attributes;
	}

    private function sessionAttributes($attributes) {
        if (!session_id()) {
            session_start();
        }

        foreach ($attributes as $k => $v) {
           $_SESSION[$k] = $v[0];
        }
    }


	/**
	 * Log in using an external authentication helper.
	 *
	 * @param array &$state  Information about the current authentication.
	 */
	public function authenticate(&$state) {
		assert('is_array($state)');

		$attributes = $this->getUser();

		if ($attributes !== NULL) {
			/*
			 * The user is already authenticated.
			 *
			 * Add the users attributes to the $state-array, and return control
			 * to the authentication process.
			 */
            $state['Attributes'] = $attributes;
            return;
        }

        /*
         * The user isn't authenticated. We therefore need to
         * send the user to the login page.
         */

        $auth = FALSE;
        $userApi = new SEQRD_Remote_User_API($this->realm_key_id, $this->api_url);

        $state['LDAPSeqrd:AuthID'] = $this->authId;

        if (!empty($_REQUEST['auth_type']) && $_REQUEST['auth_type'] === 'ldap') {
            try {
                /* Attempt to log in. */
                $username = $_REQUEST['username'];
                $password = $_REQUEST['password'];
                $attributes = $this->ldapConfig->login($username, $password);

                $this->sessionAttributes($attributes);
                $auth = TRUE;
            } catch (SimpleSAML_Error_Error $e) {
                /*
                 * Login failed. Return the error code to the login form, so that it
                 * can display an error message to the user.
                 */
                return $e->getErrorCode();
            }
        } else if (!empty($_REQUEST['auth_type']) && $_REQUEST['auth_type'] === 'seqrd') {
            $siteApi = new SEQRD_Remote_Realm_API($this->realm_key_id, $this->realm_secret_key, $this->api_url);
            $missingRealm = FALSE;
            $noSetSession = FALSE;

            if (!empty($_SESSION['seqrd_session_id'])) {
                $check = $userApi->checkSessionStatus($_SESSION['seqrd_session_id']);
                if (!empty($check['status']) && $check['status'] === 'pending') {
                    //Pended too long.
                }
                if (!empty ($check['return']) && $check['return'] === 'error') {
                    //Invalid login, give them a new code
                } else if (!empty($check['signature'])) {
                    //Should be logged in
                    $decoded = $siteApi->checkSigGetData($check);
                    if ($decoded) {
                        $user = $siteApi->userGet($decoded['user_id']);
                        $_SESSION['user_meta'] = array();
                        foreach ($user as $key => $val) {
                            if (in_array(strtolower($key), ['user_id', 'return', 'status_code'])) {
                                continue;
                            } else {
                                $_SESSION['user_meta'][$key] = $val;
                            }
                        }
                        if (!empty($user['seqrd_username'])) {
                            $_SESSION['uid'] = $user['seqrd_username'];
                        } else {
                            $_SESSION['uid'] = $decoded['user_id'];
                        }

                        // If we make it here, we're auth. We'll redirect below
                        $auth = TRUE;
                    } else {
                        SimpleSAML_Auth_State::throwException($state,
                                new SimpleSAML_Error_Exception('Unable to match payload signature with private key.'));
                    }
                }
            } else {
                SimpleSAML_Auth_State::throwException($state,
                        new SimpleSAML_Error_Exception('Expected a session_id in payload.'));
            }
        }

        /*
         * First we add the identifier of this authentication source
         * to the state array, so that we know where to resume.
         */


        /*
         * We need to save the $state-array, so that we can resume the
         * login process after authentication.
         *
         * Note the second parameter to the saveState-function. This is a
         * unique identifier for where the state was saved, and must be used
         * again when we retrieve the state.
         *
         * The reason for it is to prevent
         * attacks where the user takes a $state-array saved in one location
         * and restores it in another location, and thus bypasses steps in
         * the authentication process.
         */
        $stateId = SimpleSAML_Auth_State::saveState($state, 'LDAPSeqrd:External');

        /*
         * Now we generate an URL the user should return to after authentication.
         * We assume that whatever authentication page we send the user to has an
         * option to return the user to a specific page afterwards.
         */
        $returnTo = SimpleSAML_Module::getModuleURL('ldapSeqrd/resume.php', array(
                    'State' => $stateId,
                    ));

        /*
         * The redirect to the authentication page.
         *
         */
        if (!$auth) {
            $challenge = $userApi->loginChallenge();

            if ($challenge['return'] == 'error') {
                // We should add better bailing code here, like the option to send a message to the auth page
                // which indicates an error in the seqrd portion
                return;
            }

            $_SESSION['seqrd_session_id'] = $challenge['session_id'];
            $_SESSION['qrUrl'] = $challenge['qr_url'];
            $_SESSION['authUrl'] = $this->api_url 
                . "?s=" . $challenge['session_id']
                . "&c=" . $challenge['challenge']
                . "&r=" . $challenge['realm_key_id'];
            $_SESSION['realm_key_id'] = $this->realm_key_id;

            /*
             * Get the URL of the authentication page.
             *
             * Here we use the getModuleURL function again, since the authentication page
             * is also part of this module, but in a real example, this would likely be
             * the absolute URL of the login page for the site.
             */
            $authPage = SimpleSAML_Module::getModuleURL('ldapSeqrd/authpage.php');

            SimpleSAML_Utilities::redirect($authPage, array());
        } else {
            SimpleSAML_Utilities::redirect($returnTo, array());
        }

        /*
         * The redirect function never returns, so we never get this far.
         */
        assert('FALSE');
    }


    /**
     * Resume authentication process.
     *
     * This function resumes the authentication process after the user has
     * entered his or her credentials.
     *
     * @param array &$state  The authentication state.
     */
    public static function resume() {

        /*
         * First we need to restore the $state-array. We should have the identifier for
         * it in the 'State' request parameter.
         */
        if (!isset($_REQUEST['State'])) {
            throw new SimpleSAML_Error_BadRequest('Missing "State" parameter.');
        }
        $stateId = (string)$_REQUEST['State'];

        /*
         * Once again, note the second parameter to the loadState function. This must
         * match the string we used in the saveState-call above.
         */
        $state = SimpleSAML_Auth_State::loadState($stateId, 'LDAPSeqrd:External');

        /*
         * Now we have the $state-array, and can use it to locate the authentication
         * source.
         */
        $source = SimpleSAML_Auth_Source::getById($state['LDAPSeqrd:AuthID']);
        if ($source === NULL) {
            /*
             * The only way this should fail is if we remove or rename the authentication source
             * while the user is at the login page.
             */
            throw new SimpleSAML_Error_Exception('Could not find authentication source with id ' . $state[self::AUTHID]);
        }

        /*
         * Make sure that we haven't switched the source type while the
         * user was at the authentication page. This can only happen if we
         * change config/authsources.php while an user is logging in.
         */
        if (! ($source instanceof self)) {
            throw new SimpleSAML_Error_Exception('Authentication source type changed.');
        }


        /*
         * OK, now we know that our current state is sane. Time to actually log the user in.
         *
         * First we check that the user is acutally logged in, and didn't simply skip the login page.
         */
        $attributes = $source->getUser();
        if ($attributes === NULL) {
            /*
             * The user isn't authenticated.
             *
             * Here we simply throw an exception, but we could also redirect the user back to the
             * login page.
             */
            throw new SimpleSAML_Error_Exception('User not authenticated after login page.');
        }

        /*
         * So, we have a valid user. Time to resume the authentication process where we
         * paused it in the authenticate()-function above.
         */

        $state['Attributes'] = $attributes;
        SimpleSAML_Auth_Source::completeAuth($state);

        /*
         * The completeAuth-function never returns, so we never get this far.
         */
        assert('FALSE');
    }



}
?>

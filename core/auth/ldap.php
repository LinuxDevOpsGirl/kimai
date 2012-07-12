<?php

/**
 * Copyright (C) 2011 by Skaldrom Y. Sarg of oncode.info
 *  
 * This is free software. Use it however you want.
 */
//require_once(WEBROOT.'auth/base.php');
require_once(WEBROOT . 'auth/kimai.php');

class LdapAuth extends AuthBase {

    /** Your LDAP-Server */
    private $LADP_SERVER = 'ldap://localhost';
    /** Case-insensitivity of some Servers may confuse the case-sensitive-accounting system. */
    private $LDAP_FORCE_USERNAME_LOWERCASE = true;
    /** Preprends to username */
    private $LDAP_USERNAME_PREFIX = 'cn=';
    /** Appends to username */
    private $LDAP_USERNAME_POSTFIX = ',dc=example,dc=com';
    /** Accounts that sould be locally verified */
    private $LDAP_LOCAL_ACCOUNTS = array('admin');
    /** Automatically create a user in kimai if the login is successful. */
    private $LDAP_USER_AUTOCREATE = true;

    private $kimaiAuth = null;


    public function __construct($database = null, $kga = null) {
        parent::__construct($database, $kga);
        $this->kimaiAuth = new KimaiAuth($database, $kga);
    }

    public function authenticate($username, $password, &$userId) {
        // Check if username should be authenticated locally
        if (in_array($username, $this->LDAP_LOCAL_ACCOUNTS)) {
            return $this->kimaiAuth->authenticate($username, $password, $userId);
        }

        // Check environment sanity
        if (!function_exists('ldap_bind')) {
            echo 'ldap is not installed!';
            $userId = false;
            return false;
        }

        // Check if username is legal
        $check_username = trim($username);

        if (!$check_username || !trim($password) || ($this->LDAP_FORCE_USERNAME_LOWERCASE && strtolower($check_username) !== $check_username)) {
            $userId = false;
            return false;
        }

        // Connect to LDAP
        $connect_result = ldap_connect($this->LADP_SERVER);
        if (!$connect_result) {
            echo "Cannot connect to ", $this->LADP_SERVER;
            $userId = false;
            return false;
        }

        ldap_set_option($connect_result, LDAP_OPT_PROTOCOL_VERSION, 3);

        // Try to bind. Binding means user and pwd are valid.
        $bind_result = ldap_bind($connect_result, $this->LDAP_USERNAME_PREFIX . $check_username . $this->LDAP_USERNAME_POSTFIX, $password);

        if (!$bind_result) {
            // Nope!
            $userId = false;
            return false;
        }
        ldap_unbind($connect_result);

        // User is authenticated. Does it exist in Kimai yet?
        $check_username = $this->LDAP_FORCE_USERNAME_LOWERCASE ? strtolower($check_username) : $check_username;

        $userId = $this->database->user_name2id($check_username);
        if ($userId === false)  {
            // User does not exist (yet)
            if ($this->LDAP_USER_AUTOCREATE) { // Create it!
                $userId = $this->database->user_create(array(
                    'name' => $check_username,
                    'status' => 2,
                    'active' => 1
                        ));
                $this->database->setGroupMemberships($userId,array($this->getDefaultGroupId()));

                // Set a password, to calm kimai down
                $usr_data = array('password' => md5($this->kga['password_salt'] . md5(uniqid(rand(), true)) . $this->kga['password_salt']));
                $this->database->user_edit($userId, $usr_data);
            } else {
                $userId = false;
                return false;
            }
        }

        return true;
    }

}

// There should be NO trailing whitespaces.
?>
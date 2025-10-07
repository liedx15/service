<?php
// $Id: authenticator.class.php,v 1.2 2008/09/01 17:35:01 alfabravo Exp $
if (!defined('DP_BASE_DIR')) {
    die('You should not access this file directly.');
}

/*
 * Factory de autenticación
 */
function &getAuth($auth_mode) {
    switch ($auth_mode) {
        case 'ldap':
            $auth = new LDAPAuthenticator();
            return $auth;
        case 'pn':
            $auth = new PostNukeAuthenticator();
            return $auth;
        default:
            $auth = new SQLAuthenticator();
            return $auth;
    }
}

/* ============================================================
 *  SQLAuthenticator (base)
 * ============================================================ */
class SQLAuthenticator
{
    var $user_id   = null;
    var $username  = null;

    function authenticate($username, $password)
    {
        GLOBAL $db, $AppUI;

        $this->username = (string)$username;
        $u = addslashes($this->username);

        $q = new DBQuery;
        $q->addTable('users');
        $q->addQuery('user_id, user_password');
        $q->addWhere("user_username = '{$u}'");
        $rs = $q->exec();
        if (!$rs) {
            $q->clear();
            return false;
        }

        $row = $rs->FetchRow();
        if (!$row) {
            $q->clear();
            return false;
        }

        $this->user_id = (int)$row['user_id'];
        $q->clear();

        // dotProject clásico guarda MD5
        return (md5($password) === $row['user_password']);
    }

    /**
     * Si se pasa $username, devuelve el ID consultando BD.
     * Si no, devuelve el último user_id autenticado.
     */
    function userId($username = null)
    {
        if ($username === null) {
            return $this->user_id;
        }

        GLOBAL $db;
        $u = addslashes((string)$username);

        $q = new DBQuery;
        $q->addTable('users');
        $q->addQuery('user_id');
        $q->addWhere("user_username = '{$u}'");
        $rs = $q->exec();
        $row = $rs ? $rs->FetchRow() : null;
        $q->clear();

        return $row ? (int)$row['user_id'] : null;
    }
}

/* ============================================================
 *  PostNukeAuthenticator
 * ============================================================ */
class PostNukeAuthenticator extends SQLAuthenticator
{
    var $fallback = false;

    // PHP 7/8
    function __construct()
    {
        global $dPconfig;
        $this->fallback = isset($dPconfig['postnuke_allow_login']) ? (bool)$dPconfig['postnuke_allow_login'] : false;
    }
    // compat legacy
    function PostNukeAuthenticator() { $this->__construct(); }

    function authenticate($username, $password)
    {
        global $db, $AppUI;

        if (!isset($_REQUEST['userdata'])) {
            if ($this->fallback) {
                return parent::authenticate($username, $password);
            } else {
                if (method_exists($AppUI, 'setMsg')) {
                    $AppUI->setMsg($AppUI->_('You have not configured your PostNuke site correctly'));
                }
                return false;
            }
        }

        $raw = urldecode((string)$_REQUEST['userdata']);
        $compressed = base64_decode($raw, true);
        if ($compressed === false) {
            if (method_exists($AppUI, 'setMsg')) {
                $AppUI->setMsg($AppUI->_('The credentials supplied were missing or corrupted') . ' (1)');
            }
            return false;
        }

        $userdata = @gzuncompress($compressed);
        if ($userdata === false) {
            if (method_exists($AppUI, 'setMsg')) {
                $AppUI->setMsg($AppUI->_('The credentials supplied were missing or corrupted') . ' (2)');
            }
            return false;
        }

        // comparar, NO asignar
        $check = isset($_REQUEST['check']) ? (string)$_REQUEST['check'] : '';
        if ($check !== md5($userdata)) {
            if (method_exists($AppUI, 'setMsg')) {
                $AppUI->setMsg($AppUI->_('The credentials supplied were missing or corrupted') . ' (3)');
            }
            return false;
        }

        $user_data = @unserialize($userdata);
        if ($user_data === false || !is_array($user_data)) {
            if (method_exists($AppUI, 'setMsg')) {
                $AppUI->setMsg($AppUI->_('The credentials supplied were missing or corrupted') . ' (4)');
            }
            return false;
        }

        // Normaliza campos
        $username       = trim((string)($user_data['login']   ?? ''));
        $this->username = $username;
        $fullname       = trim((string)($user_data['name']    ?? ''));
        $names          = $fullname !== '' ? explode(' ', $fullname) : [];
        $last_name      = $names ? array_pop($names) : '';
        $first_name     = $names ? implode(' ', $names) : '';
        $passwd         = trim((string)($user_data['passwd']  ?? ''));
        $email          = trim((string)($user_data['email']   ?? ''));

        if ($username === '' || $passwd === '') {
            if (method_exists($AppUI, 'setMsg')) {
                $AppUI->setMsg($AppUI->_('Missing username or password'));
            }
            return false;
        }

        // Buscar usuario
        $q = new DBQuery;
        $q->addTable('users');
        $q->addQuery('user_id, user_password, user_contact');
        $q->addWhere("user_username = '" . addslashes($username) . "'");
        $rs = $q->exec();
        if (!$rs) {
            if (method_exists($AppUI, 'setMsg')) {
                $AppUI->setMsg($AppUI->_('Failed to get user details') . ' - error was ' . $db->ErrorMsg());
            }
            return false;
        }

        if ((int)$rs->RecordCount() < 1) {
            // Crear usuario (guardar MD5)
            $q->clear();
            $this->createsqluser($username, $passwd, $email, $first_name, $last_name);
            return true;
        }

        // Actualizar
        $row = $rs->FetchRow();
        if (!$row) {
            if (method_exists($AppUI, 'setMsg')) {
                $AppUI->setMsg($AppUI->_('Failed to retrieve user detail'));
            }
            return false;
        }

        $this->user_id = (int)$row['user_id'];

        $q->clear();
        $q->addTable('users');
        $q->addUpdate('user_password', md5($passwd)); // MD5 para compat
        $q->addWhere('user_id = ' . (int)$this->user_id);
        $q->exec(); // no cortar si falla
        $q->clear();

        if (!empty($row['user_contact'])) {
            $q->addTable('contacts');
            $q->addUpdate('contact_first_name', $first_name);
            $q->addUpdate('contact_last_name',  $last_name);
            $q->addUpdate('contact_email',      $email);
            $q->addWhere('contact_id = ' . (int)$row['user_contact']);
            $q->exec();
            $q->clear();
        }

        return true;
    }

    function createsqluser($username, $password, $email, $first, $last)
    {
        GLOBAL $db, $AppUI;

        require_once($AppUI->getModuleClass("contacts"));

        $c = new CContact();
        $c->contact_first_name = $first;
        $c->contact_last_name  = $last;
        $c->contact_email      = $email;
        $c->contact_order_by   = trim($last . ', ' . $first, ', ');

        db_insertObject('contacts', $c, 'contact_id');
        if (!$c->contact_id) {
            if (method_exists($AppUI, 'setMsg')) {
                $AppUI->setMsg($AppUI->_('Failed to create user details'));
            }
            return false;
        }

        $q = new DBQuery;
        $q->addTable('users');
        $q->addInsert('user_username', $username );
        $q->addInsert('user_password', md5($password)); // MD5
        $q->addInsert('user_type',     '1');
        $q->addInsert('user_contact',  (int)$c->contact_id);
        if (!$q->exec()) {
            if (method_exists($AppUI, 'setMsg')) {
                $AppUI->setMsg($AppUI->_('Failed to create user credentials'));
            }
            return false;
        }

        $this->user_id = (int)$db->Insert_ID();
        $q->clear();

        $acl =& $AppUI->acl();
        $acl->insertUserRole($acl->get_group_id('anon'), $this->user_id);

        return true;
    }
}

/* ============================================================
 *  LDAPAuthenticator
 * ============================================================ */
class LDAPAuthenticator extends SQLAuthenticator
{
    var $ldap_host;
    var $ldap_port;
    var $ldap_version;
    var $base_dn;
    var $ldap_search_user;
    var $ldap_search_pass;
    var $filter;

    var $fallback = false;
    var $user_id  = null;
    var $username = null;

    // PHP 7/8
    function __construct()
    {
        GLOBAL $dPconfig;

        $this->fallback         = isset($dPconfig['ldap_allow_login']) ? (bool)$dPconfig['ldap_allow_login'] : false;
        $this->ldap_host        = $dPconfig['ldap_host']        ?? '127.0.0.1';
        $this->ldap_port        = $dPconfig['ldap_port']        ?? 389;
        $this->ldap_version     = $dPconfig['ldap_version']     ?? 3;
        $this->base_dn          = $dPconfig['ldap_base_dn']     ?? '';
        $this->ldap_search_user = $dPconfig['ldap_search_user'] ?? '';
        $this->ldap_search_pass = $dPconfig['ldap_search_pass'] ?? '';
        $this->filter           = $dPconfig['ldap_user_filter'] ?? '(uid=%USERNAME%)';
    }
    // compat anterior
    function LDAPAuthenticator() { $this->__construct(); }

    function authenticate($username, $password)
    {
        $this->username = $username;

        if (strlen($password) === 0) {
            return false;
        }

        if ($this->fallback && parent::authenticate($username, $password)) {
            return true;
        }

        $rs = @ldap_connect($this->ldap_host, (int)$this->ldap_port);
        if (!$rs) {
            return false;
        }
        @ldap_set_option($rs, LDAP_OPT_PROTOCOL_VERSION, (int)$this->ldap_version);
        @ldap_set_option($rs, LDAP_OPT_REFERRALS, 0);

        $ldap_bind_dn = ($this->ldap_search_user !== '') ? $this->ldap_search_user : NULL;
        $ldap_bind_pw = ($this->ldap_search_pass !== '') ? $this->ldap_search_pass : NULL;
        if (!@ldap_bind($rs, $ldap_bind_dn, $ldap_bind_pw)) {
            return false;
        }

        $filter_r = html_entity_decode(str_replace('%USERNAME%', $username, $this->filter), ENT_COMPAT, 'UTF-8');
        $result   = @ldap_search($rs, $this->base_dn, $filter_r);
        if (!$result) return false;

        $entries = @ldap_get_entries($rs, $result);
        if (!$entries || !isset($entries['count']) || (int)$entries['count'] === 0) {
            return false;
        }

        $first_user   = $entries[0];
        $ldap_user_dn = $first_user['dn'];

        if (!@ldap_bind($rs, $ldap_user_dn, $password)) {
            return false;
        }

        if ($this->userExists($username)) {
            return true;
        }
        $this->createsqluser($username, $password, $first_user);
        return true;
    }

    function userExists($username)
    {
        GLOBAL $db;
        $q = new DBQuery;
        $q->addTable('users');
        $q->addWhere("user_username = '" . addslashes($username) . "'");
        $rs = $q->exec();
        $exists = ($rs && $rs->RecordCount() > 0);
        $q->clear();
        return $exists;
    }

    // misma firma que el padre
    function userId($username = null)
    {
        return parent::userId($username);
    }

    function createsqluser($username, $password, $ldap_attribs = array())
    {
        GLOBAL $db, $AppUI;
        $hash_pass = md5($password);

        require_once($AppUI->getModuleClass('contacts'));

        $c = new CContact();
        $c->contact_first_name = isset($ldap_attribs['givenname'][0])       ? $ldap_attribs['givenname'][0]       : '';
        $c->contact_last_name  = isset($ldap_attribs['sn'][0])              ? $ldap_attribs['sn'][0]              : '';
        $c->contact_email      = isset($ldap_attribs['mail'][0])            ? $ldap_attribs['mail'][0]            : '';
        $c->contact_phone      = isset($ldap_attribs['telephonenumber'][0]) ? $ldap_attribs['telephonenumber'][0] : '';
        $c->contact_mobile     = isset($ldap_attribs['mobile'][0])          ? $ldap_attribs['mobile'][0]          : '';
        $c->contact_city       = isset($ldap_attribs['l'][0])               ? $ldap_attribs['l'][0]               : '';
        $c->contact_country    = isset($ldap_attribs['country'][0])         ? $ldap_attribs['country'][0]         : '';
        $c->contact_state      = isset($ldap_attribs['st'][0])              ? $ldap_attribs['st'][0]              : '';
        $c->contact_zip        = isset($ldap_attribs['postalcode'][0])      ? $ldap_attribs['postalcode'][0]      : '';
        $c->contact_job        = isset($ldap_attribs['title'][0])           ? $ldap_attribs['title'][0]           : '';
        $c->contact_order_by   = trim($c->contact_last_name . ', ' . $c->contact_first_name, ', ');

        db_insertObject('contacts', $c, 'contact_id');
        $contact_id = ($c->contact_id == NULL) ? 'NULL' : (int)$c->contact_id;

        $q = new DBQuery;
        $q->addTable('users');
        $q->addInsert('user_username', $username);
        $q->addInsert('user_password', $hash_pass);
        $q->addInsert('user_type',    '1');
        $q->addInsert('user_contact', $contact_id);
        $q->exec();
        $this->user_id = $db->Insert_ID();
        $q->clear();

        $acl =& $AppUI->acl();
        $acl->insertUserRole($acl->get_group_id('anon'), $this->user_id);
    }
}

?>

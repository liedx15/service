<?php /* $Id: index.php,v 1.7 2009/01/20 03:11:04 alfabravo Exp $ */

// ===================== DEBUG LOCAL (opcional) =====================
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . DIRECTORY_SEPARATOR . 'php_errors.log');
date_default_timezone_set('America/Bogota');
// =================================================================

$loginFromPage = 'index.php';


// --- Bootstrap mínimo imprescindible ---
require_once __DIR__ . '/base.php';

clearstatcache();
if (!is_file(DP_BASE_DIR . '/includes/config.php')) {
    echo '<html><head><meta http-equiv="refresh" content="5; URL=' . DP_BASE_URL . '/install/index.php"></head><body>';
    echo 'Fatal Error. You haven\'t created a config file yet.<br/><a href="./install/index.php">'
       . 'Click Here To Start Installation and Create One!</a> (forwarded in 5 sec.)</body></html>';
    exit;
}

require_once DP_BASE_DIR . '/includes/config.php';
if (!isset($GLOBALS['OS_WIN'])) {
    $GLOBALS['OS_WIN'] = (stristr(PHP_OS, 'WIN') !== false);
}

// Cargas base
require_once DP_BASE_DIR . '/includes/main_functions.php';
require_once DP_BASE_DIR . '/includes/db_adodb.php';
require_once DP_BASE_DIR . '/includes/db_connect.php';
require_once DP_BASE_DIR . '/classes/ui.class.php';
require_once DP_BASE_DIR . '/classes/permissions.class.php';
require_once DP_BASE_DIR . '/includes/session.php';

// --- Sesión y AppUI ---
$suppressHeaders = dPgetParam($_GET, 'suppressHeaders', false);
dpSessionStart(['AppUI']); // usa tu función del session.php (PHP 7/8 safe)

if (!isset($_SESSION['AppUI']) || !is_object($_SESSION['AppUI'])) {
    $_SESSION['AppUI'] = new CAppUI();
}
$AppUI =& $_SESSION['AppUI'];
if (!isset($AppUI->user_id)) {
    $AppUI->user_id = 0;
}

// --- Lost password (antes que nada) ---
if (dPgetParam($_POST, 'lostpass', 0)) {
    $uistyle = dPgetConfig('host_style') ?: 'default';
    $AppUI->setUserLocale();
    @include_once DP_BASE_DIR . '/locales/' . $AppUI->user_locale . '/locales.php';
    @include_once DP_BASE_DIR . '/locales/core.php';
    setlocale(LC_TIME, $AppUI->user_lang);

    if (dPgetParam($_REQUEST, 'sendpass', 0)) {
        require DP_BASE_DIR . '/includes/sendpass.php';
        sendNewPass();
    } else {
        require DP_BASE_DIR . '/style/' . $uistyle . '/lostpass.php';
    }
    exit;
}

// --- Intento de login (POST) ---
$redirect = dPgetCleanParam($_REQUEST, 'redirect', '');
if (!is_string($redirect)) { $redirect = ''; }

// --- Intento de login (POST) ---
if (isset($_REQUEST['login'])) {
    $username = dPgetCleanParam($_POST, 'username', '');
    $password = dPgetCleanParam($_POST, 'password', '');

    // Carga de locales para mensajes
    $AppUI->setUserLocale();
    @include_once DP_BASE_DIR . '/locales/' . $AppUI->user_locale . '/locales.php';
    @include_once DP_BASE_DIR . '/locales/core.php';

    // Log rápida para ver que entra aquí
    error_log("[LOGIN] Intento: user={$username}");

    $ok = $AppUI->login($username, $password);

    if (!$ok) {
        $AppUI->setMsg('Login Failed');
        error_log("[LOGIN] Falló AppUI->login()");
        // Sigue para que se pinte login con el mensaje
    } else {
        // Asegura persistencia de sesión antes de redirigir
        $_SESSION['AppUI'] = $AppUI;
        if (function_exists('session_write_close')) {
            @session_write_close();
        }

        // Historial (opcional)
        @addHistory('login', (int)$AppUI->user_id, 'login', ($AppUI->user_first_name ?? '') . ' ' . ($AppUI->user_last_name ?? ''));

        // Si no vino redirect, manda al home por defecto
        if ($redirect === '' || $redirect === null) {
            $redirect = DP_BASE_URL . '/index.php';
        }

        // Evita cabeceras previas
        if (!headers_sent()) {
            header('Location: ' . $redirect, true, 302);
            exit;
        } else {
            echo '<script>location.href=' . json_encode($redirect) . ';</script>';
            exit;
        }
    }
}

// --- Preferencias por defecto si no hay login ---
if ($AppUI->doLogin()) {
    $AppUI->loadPrefs(0);
}
$AppUI->checkStyle();

// --- Decidir si hay que mostrar LOGIN ---
/*
   En dotProject clásico:
   - doLogin() === true  -> NO hay sesión válida -> mostrar login
   - doLogin() === false -> hay sesión válida
   Además, verificamos user_id > 0.
*/
$needLogin = true;
try {
    $needLogin = ($AppUI->doLogin() === true) || ((int)$AppUI->user_id <= 0);
} catch (Throwable $e) {
    $needLogin = true;
}

// --- Mostrar LOGIN y salir (sin header/footer) ---
if ($needLogin) {
    $uistyle = $AppUI->getPref('UISTYLE') ?: dPgetConfig('host_style') ?: 'default';
    $AppUI->setUserLocale();
    @include_once DP_BASE_DIR . '/locales/' . $AppUI->user_locale . '/locales.php';
    @include_once DP_BASE_DIR . '/locales/core.php';
    setlocale(LC_TIME, $AppUI->user_lang);

    // Cabecera de charset (no usar header/footer del tema aquí)
    if (!headers_sent()) {
        $charset = isset($locale_char_set) ? $locale_char_set : 'UTF-8';
        header('Content-Type: text/html; charset=' . $charset);
        // Evitar cache
        header('Expires: ' . gmdate('D, d M Y H:i:s', mktime(0,0,0, date("m")+1, date("d"), date("Y"))) . ' GMT');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
        header('Cache-Control: no-cache, must-revalidate, no-store, post-check=0, pre-check=0');
        header('Pragma: no-cache');
    }

    // Variables que usa login.php
    $dPconfig = $GLOBALS['dPconfig'] ?? [];
    require DP_BASE_DIR . '/style/' . $uistyle . '/login.php';
    exit;
}

// ===================================================================
//            A PARTIR DE AQUÍ: USUARIO LOGUEADO
// ===================================================================

// Parámetros por defecto de módulo/acción
$uistyle = $AppUI->getPref('UISTYLE') ?: dPgetConfig('host_style') ?: 'default';
$m = '';
$a = '';
$u = '';

// Soporte permisos + locales
require_once DP_BASE_DIR . '/includes/permissions.php';

$def_a = 'index';
if (!isset($_GET['m']) && !empty($dPconfig['default_view_m'])) {
    $m = $dPconfig['default_view_m'];
    $def_a = !empty($dPconfig['default_view_a']) ? $dPconfig['default_view_a'] : $def_a;
    $tab = $dPconfig['default_view_tab'];
} else {
    $m = $AppUI->checkFileName(dPgetCleanParam($_GET, 'm', getReadableModule()));
}
$a = $AppUI->checkFileName(dPgetCleanParam($_GET, 'a', $def_a));
$u = $AppUI->checkFileName(dPgetCleanParam($_GET, 'u', ''));

// Locales por módulo
@include_once DP_BASE_DIR . '/locales/' . $AppUI->user_locale . '/locales.php';
@include_once DP_BASE_DIR . '/locales/core.php';
setlocale(LC_TIME, $AppUI->user_lang);
$m_config = dPgetConfig($m);
@include_once DP_BASE_DIR . '/functions/' . $m . '_func.php';

// Permisos
$perms =& $AppUI->acl();
$canAccess = $perms->checkModule($m, 'access');
$canRead   = $perms->checkModule($m, 'view');
$canEdit   = $perms->checkModule($m, 'edit');
$canAuthor = $perms->checkModule($m, 'add');
$canDelete = $perms->checkModule($m, 'delete');

// Cabeceras sólo si vamos a pintar UI
if (!$suppressHeaders) {
    if (isset($locale_char_set)) {
        header('Content-Type: text/html; charset=' . $locale_char_set);
    }
}

// Clases del módulo si existen
$modclass = $AppUI->getModuleClass($m);
if (file_exists($modclass)) {
    include_once $modclass;
}
if ($u && file_exists(DP_BASE_DIR . '/modules/' . $m . '/' . $u . '/' . $u . '.class.php')) {
    include_once DP_BASE_DIR . '/modules/' . $m . '/' . $u . '/' . $u . '.class.php';
}

// dosql
if (isset($_REQUEST['dosql'])) {
    require DP_BASE_DIR . '/modules/' . $m . '/' . ($u ? ($u . '/') : '') . $AppUI->checkFileName($_REQUEST['dosql']) . '.php';
}

// Output principal
include DP_BASE_DIR . '/style/' . $uistyle . '/overrides.php';
ob_start();
if (!$suppressHeaders) {
    require DP_BASE_DIR . '/style/' . $uistyle . '/header.php';
}

// Tabs (cacheadas en sesión)
if (!isset($_SESSION['all_tabs'][$m])) {
    if (!isset($_SESSION['all_tabs'])) {
        $_SESSION['all_tabs'] = array();
    }
    $_SESSION['all_tabs'][$m] = array();
    $all_tabs =& $_SESSION['all_tabs'][$m];
    foreach ($AppUI->getActiveModules() as $dir => $module) {
        if (!$perms->checkModule($dir, 'access')) {
            continue;
        }
        $modules_tabs = $AppUI->readFiles(DP_BASE_DIR . '/modules/' . $dir . '/', '^' . $m . '_tab.*\.php');
        foreach ($modules_tabs as $mod_tab) {
            $nameparts = explode('.', $mod_tab);
            $filename = substr($mod_tab, 0, -4);
            if (count($nameparts) > 3) {
                $file = $nameparts[1];
                if (!isset($all_tabs[$file])) {
                    $all_tabs[$file] = array();
                }
                $arr =& $all_tabs[$file];
                $name = $nameparts[2];
            } else {
                $arr =& $all_tabs;
                $name = $nameparts[1];
            }
            $arr[] = array(
                'name'   => ucfirst(str_replace('_', ' ', $name)),
                'file'   => DP_BASE_DIR . '/modules/' . $dir . '/' . $filename,
                'module' => $dir
            );
            unset($arr);
        }
    }
} else {
    $all_tabs =& $_SESSION['all_tabs'][$m];
}

// Carga del archivo principal del módulo
$module_file = DP_BASE_DIR . '/modules/' . $m . '/' . ($u ? ($u . '/') : '') . $a . '.php';
if (file_exists($module_file)) {
    require $module_file;
} else {
    $titleBlock = new CTitleBlock('Warning', 'log-error.gif');
    $titleBlock->show();
    echo $AppUI->_('Missing file. Possible Module "' . $m . '" missing!');
}

if (!$suppressHeaders) {
    echo '<iframe name="thread" src="' . DP_BASE_URL . '/modules/index.html" width="0" height="0" frameborder="0"></iframe>';
    require DP_BASE_DIR . '/style/' . $uistyle . '/footer.php';
}
ob_end_flush();

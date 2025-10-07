<?php
/* STYLE/DEFAULT login.php - compatible PHP 8, charset seguro UTF-8 */

/* --- 0) Bootstrap mínimo si entran directo a este archivo --- */
if (!defined('DP_BASE_DIR')) {
    $possibleUi = __DIR__ . '/../../classes/ui.class.php';
    if (file_exists($possibleUi)) {
        require_once $possibleUi;
    }
}

/* --- 1) Instancia / fallback de $AppUI --- */
if (!isset($AppUI) || !is_object($AppUI)) {
    if (!class_exists('CAppUI') && defined('DP_BASE_DIR')) {
        @require_once DP_BASE_DIR . '/classes/ui.class.php';
    }
    if (class_exists('CAppUI')) {
        $AppUI = new CAppUI();
        if (method_exists($AppUI, 'setUserLocale')) {
            $AppUI->setUserLocale();
        }
    } else {
        // Fallback mínimo para evitar fatales si no hay bootstrap
        $AppUI = (object) [
            'user_locale' => 'es-CO',
            '_'           => static function ($s) { return $s; },
            'getMsg'      => static function () { return ''; },
            'getVersion'  => static function () { return ''; },
        ];
    }
}

/* --- 2) Config y defaults --- */
if (!isset($dPconfig) || !is_array($dPconfig)) {
    $dPconfig = [];
}
$uistyle = isset($uistyle) && $uistyle !== ''
    ? $uistyle
    : (function_exists('dPgetConfig') ? (dPgetConfig('host_style') ?: 'occidente') : 'occidente');

$page_title = $dPconfig['page_title'] ?? 'itC - Service Point';

/* redirect puede venir por GET/POST, se usa si index lo pasó */
$redirect = '';
if (isset($_POST['redirect'])) $redirect = (string)$_POST['redirect'];
elseif (isset($_GET['redirect'])) $redirect = (string)$_GET['redirect'];

/* --- 3) Charset/locale seguros (NO usar locale como charset) --- */
$locale_char_set = 'UTF-8';        // SIEMPRE charset válido para htmlspecialchars
if (function_exists('mb_internal_encoding')) {
    @mb_internal_encoding('UTF-8');
}
@ini_set('default_charset', 'UTF-8');

/* --- 4) Helpers --- */
function enc(): string { return 'UTF-8'; }
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, enc()); }

$_tCallable = (is_object($AppUI) && method_exists($AppUI, '_'))
    ? [$AppUI, '_']
    : (is_callable($AppUI->_ ?? null) ? $AppUI->_ : static function ($s) { return $s; });
function t($s) { global $_tCallable; return is_callable($_tCallable) ? call_user_func($_tCallable, $s) : $s; }

$_getMsgCallable = (is_object($AppUI) && method_exists($AppUI, 'getMsg'))
    ? [$AppUI, 'getMsg']
    : (is_callable($AppUI->getMsg ?? null) ? $AppUI->getMsg : static function () { return ''; });
function getMsgSafe(): string {
    global $_getMsgCallable;
    $r = is_callable($_getMsgCallable) ? call_user_func($_getMsgCallable) : '';
    return is_string($r) ? $r : '';
}

$version = (is_object($AppUI) && method_exists($AppUI, 'getVersion')) ? $AppUI->getVersion() : '';
$user_locale = (is_object($AppUI) && !empty($AppUI->user_locale)) ? (string)$AppUI->user_locale : 'es-CO';

/* --- 5) Acción robusta del formulario --- */
/*  - Si index.php definió $loginFromPage, se respeta.
    - Si existe base_url, se usa {base_url}/index.php
    - Si no, se calcula relativo (dos niveles arriba de /style/xxx/login.php) */
if (!empty($loginFromPage)) {
    $actionLogin = (string)$loginFromPage;
} elseif (!empty($dPconfig['base_url'])) {
    $actionLogin = rtrim($dPconfig['base_url'], '/') . '/index.php';
} else {
    $self = $_SERVER['PHP_SELF'] ?? '';
    $actionLogin = rtrim(dirname(dirname($self)), '/') . '/index.php';
}
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="<?php echo h($user_locale); ?>" lang="<?php echo h($user_locale); ?>">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=<?php echo enc(); ?>" />
    <meta http-equiv="Pragma" content="no-cache" />
    <title><?php echo h($page_title); ?></title>
    <meta name="Version" content="<?php echo h($version); ?>" />
    <link rel="stylesheet" type="text/css" href="./style/<?php echo h($uistyle); ?>/main.css" media="all" />
    <link rel="shortcut icon" href="./style/<?php echo h($uistyle); ?>/images/favicon.ico" type="image/ico" />
</head>

<body onload="document.loginform && document.loginform.username && document.loginform.username.focus();">
<form method="post" action="<?php echo h($actionLogin); ?>" name="loginform" autocomplete="on">
    <!-- IMPORTANTE: no usar hidden name="login" para no colisionar con el botón -->
    <input type="hidden" name="login_ts" value="<?php echo time();?>" />
    <input type="hidden" name="lostpass" value="0" />
    <input type="hidden" name="redirect" value="<?php echo h($redirect); ?>" />

    <div align="center" style="width: 100%; height: 100px;">&nbsp;</div>
    <div align="center">
        <div style="background:url('./style/<?php echo h($uistyle); ?>/images/inicio.jpg'); width:699px; height:414px;">
            <div style="position: relative; top: 149px">
                <div style="position: relative; left: -60px; padding: 0 0 10px 0">
                    <label for="username" class="etiqueta_login"><?php echo h(t('Username')); ?></label>
                    <input type="text" size="25" maxlength="64" id="username" name="username" class="text" />
                </div>
                <div style="position: relative; left: -40px; padding: 0 0 10px 0">
                    <label for="password" class="etiqueta_login"><?php echo h(t('Password')); ?></label>
                    <input type="password" size="25" maxlength="128" id="password" name="password" class="text" />
                </div>
                <div style="text-align: right; position: relative; right: 265px;">
                    <!-- El backend detecta el submit por este name="login" -->
                    <input type="submit" name="login" value="<?php echo h(t('login')); ?>" class="button" />
                </div>
                <div style="position: relative; left: -30px; padding: 20px 0 0 0">
                    <span class="ayuda_login" onclick="var f=document.loginform; f.lostpass.value=1; f.submit();">
                        <?php echo h(t('forgotPassword')); ?>
                    </span>
                </div>
            </div>
        </div>
    </div>
</form>

<div style="text-align:center; float:none;">
<?php
    $msg = getMsgSafe();
    if ($msg !== '') {
        // NO usar h($msg) aquí
        echo $msg;
    }
?>
</div>

<!-- (Opcional) Sello SSL -->
<div style="float:left; text-align:left; vertical-align:middle;">
    <table width="135" border="0" cellpadding="2" cellspacing="0" title="Click to Verify - VeriSign SSL">
        <tr>
            <td width="135" align="center" valign="top">
                <script type="text/javascript" src="https://seal.verisign.com/getseal?host_name=localhost&amp;size=S&amp;use_flash=NO&amp;use_transparent=NO&amp;lang=es"></script><br />
                <a href="http://www.verisign.es/products-services/security-services/ssl/ssl-information-center/" target="_blank" style="color:#000; text-decoration:none; font:bold 7px verdana,sans-serif; letter-spacing:.5px; text-align:center; margin:0; padding:0;">Acerca de los certificados SSL</a>
            </td>
        </tr>
    </table>
</div>

</body>
</html>

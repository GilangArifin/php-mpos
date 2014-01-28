<?php
/*

Copyright:: 2013, Sebastian Grewe

Licensed under the Apache License, Version 2.0 (the "License");
you may not use this file except in compliance with the License.
You may obtain a copy of the License at

http://www.apache.org/licenses/LICENSE-2.0

Unless required by applicable law or agreed to in writing, software
distributed under the License is distributed on an "AS IS" BASIS,
WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
See the License for the specific language governing permissions and
limitations under the License.

*/
// Set a decently long SECURITY key with special chars etc
define('SECURITY', '*)WT#&YHfd');
// Whether or not to check SECHASH for validity, still checks if SECURITY defined as before if disabled
define('SECHASH_CHECK', false);

// Nothing below here to configure, move along...

// change SECHASH every second, we allow up to 3 sec back for slow servers
if (SECHASH_CHECK) {
  function fip($tr=0) { return md5(SECURITY.(time()-$tr).SECURITY); }
  define('SECHASH', fip());
  function cfip() { return (fip()==SECHASH||fip(1)==SECHASH||fip(2)==SECHASH) ? 1 : 0; }
} else {
  function cfip() { return (@defined('SECURITY')) ? 1 : 0; }
}

// Used for performance calculations
$dStartTime = microtime(true);

// This should be okay
// No but Its now, - Aim
define("BASEPATH", dirname(__FILE__) . "/");

// Include our configuration (holding defines for the requires)
if (!include_once(BASEPATH . 'include/config/global.inc.php')) die('Unable to load site configuration');
if (!include_once(BASEPATH . 'include/config/security.inc.dist.php')) die('Unable to load security configuration');
if (@file_exists(BASEPATH . 'include/config/security.inc.php')) include_once(BASEPATH . 'include/config/security.inc.php');

// switch to https if config option is enabled
$hts = ($config['strict__https_only'] && (!empty($_SERVER['QUERY_STRING']))) ? "https://".$_SERVER['SERVER_NAME'].$_SERVER['SCRIPT_NAME']."?".$_SERVER['QUERY_STRING'] : "https://".$_SERVER['SERVER_NAME'].$_SERVER['SCRIPT_NAME'];
($config['strict__https_only'] && @!$_SERVER['HTTPS']) ? exit(header($hts)):0;

// Our default template to load, pages can overwrite this later
$master_template = 'master.tpl';

// Load Classes, they name defines the $ variable used
// We include all needed files here, even though our templates could load them themself
require_once(INCLUDE_DIR . '/autoloader.inc.php');

if ($config['memcache']['enabled'] && ($config['mc_antidos']['enabled'] || $config['strict'])) {
  if (PHP_OS == 'WINNT') {
    require_once(CLASS_DIR . 'memcached.class.php');
  }
  // strict mode and memcache antidos need a memcache handle
  $memcache = new Memcached();
  $memcache->addServer($config['memcache']['host'], $config['memcache']['port']);
}

if ($config['memcache']['enabled'] && $config['strict'] || $config['mc_antidos']['enabled']) {
  require_once(CLASS_DIR . '/memcache_ad.class.php');
}

if ($config['memcache']['enabled'] && $config['strict']) {
  $session = new strict_session($config, $memcache);
  if ($config['strict__verify_server'] && !$session) {
    // server not verified, session manager will kill the client verification failures
    exit(header('HTTP/1.1 401 Unauthorized'));
  }
} else {
  $session_start = @session_start();
  session_set_cookie_params(time()+$config['cookie']['duration'], $config['cookie']['path'], $config['cookie']['domain'], $config['cookie']['secure'], $config['cookie']['httponly']);
  if (!$session_start) {
    session_destroy();
    session_regenerate_id(true);
    session_start();
  }
  @setcookie(session_name(), session_id(), time()+$config['cookie']['duration'], $config['cookie']['path'], $config['cookie']['domain'], $config['cookie']['secure'], $config['cookie']['httponly']);
}

// Rate limiting
if ($config['memcache']['enabled'] && ($config['mc_antidos']['enabled'] || $config['strict'])) {
  $skip_check = false;
  // if this is an api call we need to be careful not to time them out for those calls separately
  $per_page = '';
  $ajax_calls = array(
    array('api', 'getuserbalance'),
    array('api', 'getnavbardata'),
    array('api', 'getdashboarddata'),
    array('api', 'getuserworkers')
  );
  $iac = 0;
  foreach ($ajax_calls as $ac) {
    $iac = (@$_REQUEST['page'] == $ac[0] && @$_REQUEST['action'] == $ac[1]) ? $iac+=1 : $iac;
  }
  $is_ajax_call = ($iac > 0) ? true : false;
  if ($is_ajax_call && $config['mc_antidos']['protect_ajax']) {
    $per_page = 'api';
  } else if ($is_ajax_call && !$config['mc_antidos']['protect_ajax']) {
    // protect isn't on, we'll ignore it
    $skip_check = true;
  } else if ($config['mc_antidos']['ignore_admins'] && isset($_SESSION['USERDATA']['is_admin']) && $_SESSION['USERDATA']['is_admin']) {
    $skip_check = true;
  }
  if (!$skip_check) {
    $mcad = new MemcacheAntiDos($config['mc_antidos'], $memcache, $_SERVER['REMOTE_ADDR'], $per_page, $config['memcache']);
    $rate_limit_reached_site = $mcad->rateLimitSite();
    $rate_limit_reached_api = $mcad->rateLimitAPI();
    if ($rate_limit_reached_api && $is_ajax_call && $config['mc_antidos']['protect_ajax']) {
      exit(header('HTTP/1.1 401 Unauthorized'));
    }
    $error_page = $config['mc_antidos']['error_push_page'];
    if ($rate_limit_reached_site == true) {
      if (!is_array($error_page) || count($error_page) < 1 || (empty($error_page['page']) && empty($error_page['action']))) {
        die("You are sending too many requests too fast!");
      } else {
        $_REQUEST['page'] = $error_page['page'];
        $_REQUEST['action'] = (isset($error_page['action']) && !empty($error_page['action'])) ? $error_page['action'] : $_REQUEST['action'];
      }
    }
  }
}

// Quick config check
if (@$_SESSION['USERDATA']['is_admin'] && !@$config['skip_config_tests']) {
  require_once(INCLUDE_DIR. '/admin_checks.php');
}

// Create our pages array from existing files
if (is_dir(INCLUDE_DIR . '/pages/')) {
  foreach (glob(INCLUDE_DIR . '/pages/*.inc.php') as $filepath) {
    $filename = basename($filepath);
    $pagename = substr($filename, 0, strlen($filename) - 8);
    $arrPages[$pagename] = $filename;
    $debug->append("Adding $pagename as " . $filename . " to accessible pages", 4);
  }
}

// Set a default action here if no page has been requested
@$_REQUEST['page'] = (is_array($_REQUEST['page']) || !isset($_REQUEST['page'])) ? 'home' : $_REQUEST['page'];
if (isset($_REQUEST['page']) && isset($arrPages[$_REQUEST['page']])) {
  $page = $_REQUEST['page'];
} else if (isset($_REQUEST['page']) && ! isset($arrPages[$_REQUEST['page']])) {
  $page = 'error';
} else {
  $page = 'home';
}

// Create our pages array from existing files
if (is_dir(INCLUDE_DIR . '/pages/' . $page)) {
  foreach (glob(INCLUDE_DIR . '/pages/' . $page . '/*.inc.php') as $filepath) {
    $filename = basename($filepath);
    $pagename = substr($filename, 0, strlen($filename) - 8);
    $arrActions[$pagename] = $filename;
    $debug->append("Adding $pagename as " . $filename . ".inc.php to accessible actions", 4);
  }
}
// Default to empty (nothing) if nothing set or not known
$action = (isset($_REQUEST['action']) && !is_array($_REQUEST['action'])) && isset($arrActions[$_REQUEST['action']]) ? $_REQUEST['action'] : "";

// Check csrf token validity if necessary
if ($config['csrf']['enabled'] && isset($_POST['ctoken']) && !empty($_POST['ctoken']) && !is_array($_POST['ctoken'])) {
  $csrftoken->valid = ($csrftoken->checkBasic($user->getCurrentIP(), $arrPages[$page], $_POST['ctoken'])) ? 1 : 0;
} else if ($config['csrf']['enabled'] && (!@$_POST['ctoken'] || empty($_POST['ctoken']))) {
  $csrftoken->valid = 0;
}
if ($config['csrf']['enabled']) $smarty->assign('CTOKEN', $csrftoken->getBasic($user->getCurrentIP(), $arrPages[$page]));

// Load the page code setting the content for the page OR the page action instead if set
if (!empty($action)) {
  $debug->append('Loading Action: ' . $action . ' -> ' . $arrActions[$action], 1);
  require_once(PAGES_DIR . '/' . $page . '/' . $arrActions[$action]);
} else {
  $debug->append('Loading Page: ' . $page . ' -> ' . $arrPages[$page], 1);
  require_once(PAGES_DIR . '/' . $arrPages[$page]);
}

define('PAGE', $page);
define('ACTION', $action);

// For our content inclusion
$smarty->assign("PAGE", $page);
$smarty->assign("ACTION", $action);

// Now with all loaded and processed, setup some globals we need for smarty templates
if ($page != 'api') require_once(INCLUDE_DIR . '/smarty_globals.inc.php');

// Load debug information into template
$debug->append("Loading debug information into template", 4);
$smarty->assign('DebuggerInfo', $debug->getDebugInfo());
$smarty->assign('RUNTIME', (microtime(true) - $dStartTime) * 1000);

// Display our page
if (!@$supress_master) $smarty->display($master_template, $smarty_cache_key);

// Unset any temporary values here
unset($_SESSION['POPUP']);

?>
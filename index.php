<?php
/**
 * Shaarli - The personal, minimalist, super-fast, database free, bookmarking service.
 *
 * Friendly fork by the Shaarli community:
 *  - https://github.com/shaarli/Shaarli
 *
 * Original project by sebsauvage.net:
 *  - http://sebsauvage.net/wiki/doku.php?id=php:shaarli
 *  - https://github.com/sebsauvage/Shaarli
 *
 * Licence: http://www.opensource.org/licenses/zlib-license.php
 */

// Set 'UTC' as the default timezone if it is not defined in php.ini
// See http://php.net/manual/en/datetime.configuration.php#ini.date.timezone
if (date_default_timezone_get() == '') {
    date_default_timezone_set('UTC');
}

/*
 * PHP configuration
 */

// http://server.com/x/shaarli --> /shaarli/
define('WEB_PATH', substr($_SERVER['REQUEST_URI'], 0, 1+strrpos($_SERVER['REQUEST_URI'], '/', 0)));

// High execution time in case of problematic imports/exports.
ini_set('max_input_time', '60');

// Try to set max upload file size and read
ini_set('memory_limit', '128M');
ini_set('post_max_size', '16M');
ini_set('upload_max_filesize', '16M');

// See all error except warnings
error_reporting(E_ALL^E_WARNING);

// 3rd-party libraries
if (! file_exists(__DIR__ . '/vendor/autoload.php')) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Error: missing Composer configuration\n\n"
        ."If you installed Shaarli through Git or using the development branch,\n"
        ."please refer to the installation documentation to install PHP"
        ." dependencies using Composer:\n"
        ."- https://shaarli.readthedocs.io/en/master/Server-configuration/\n"
        ."- https://shaarli.readthedocs.io/en/master/Download-and-Installation/";
    exit;
}
require_once 'inc/rain.tpl.class.php';
require_once __DIR__ . '/vendor/autoload.php';

// Shaarli library
require_once 'application/bookmark/LinkUtils.php';
require_once 'application/config/ConfigPlugin.php';
require_once 'application/http/HttpUtils.php';
require_once 'application/http/UrlUtils.php';
require_once 'application/updater/UpdaterUtils.php';
require_once 'application/FileUtils.php';
require_once 'application/TimeZone.php';
require_once 'application/Utils.php';

use Shaarli\ApplicationUtils;
use Shaarli\Bookmark\BookmarkFileService;
use Shaarli\Config\ConfigManager;
use Shaarli\Container\ContainerBuilder;
use Shaarli\History;
use Shaarli\Languages;
use Shaarli\Plugin\PluginManager;
use Shaarli\Render\PageBuilder;
use Shaarli\Security\LoginManager;
use Shaarli\Security\SessionManager;
use Slim\App;

// Ensure the PHP version is supported
try {
    ApplicationUtils::checkPHPVersion('7.1', PHP_VERSION);
} catch (Exception $exc) {
    header('Content-Type: text/plain; charset=utf-8');
    echo $exc->getMessage();
    exit;
}

define('SHAARLI_VERSION', ApplicationUtils::getVersion(__DIR__ .'/'. ApplicationUtils::$VERSION_FILE));

// Force cookie path (but do not change lifetime)
$cookie = session_get_cookie_params();
$cookiedir = '';
if (dirname($_SERVER['SCRIPT_NAME']) != '/') {
    $cookiedir = dirname($_SERVER["SCRIPT_NAME"]).'/';
}
// Set default cookie expiration and path.
session_set_cookie_params($cookie['lifetime'], $cookiedir, $_SERVER['SERVER_NAME']);
// Set session parameters on server side.
// Use cookies to store session.
ini_set('session.use_cookies', 1);
// Force cookies for session (phpsessionID forbidden in URL).
ini_set('session.use_only_cookies', 1);
// Prevent PHP form using sessionID in URL if cookies are disabled.
ini_set('session.use_trans_sid', false);

session_name('shaarli');
// Start session if needed (Some server auto-start sessions).
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Regenerate session ID if invalid or not defined in cookie.
if (isset($_COOKIE['shaarli']) && !SessionManager::checkId($_COOKIE['shaarli'])) {
    session_regenerate_id(true);
    $_COOKIE['shaarli'] = session_id();
}

$conf = new ConfigManager();

// In dev mode, throw exception on any warning
if ($conf->get('dev.debug', false)) {
    // See all errors (for debugging only)
    error_reporting(-1);

    set_error_handler(function($errno, $errstr, $errfile, $errline, array $errcontext) {
        throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
    });
}

$sessionManager = new SessionManager($_SESSION, $conf);
$loginManager = new LoginManager($conf, $sessionManager);
$loginManager->generateStaySignedInToken($_SERVER['REMOTE_ADDR']);
$clientIpId = client_ip_id($_SERVER);

// LC_MESSAGES isn't defined without php-intl, in this case use LC_COLLATE locale instead.
if (! defined('LC_MESSAGES')) {
    define('LC_MESSAGES', LC_COLLATE);
}

// Sniff browser language and set date format accordingly.
if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
    autoLocale($_SERVER['HTTP_ACCEPT_LANGUAGE']);
}

new Languages(setlocale(LC_MESSAGES, 0), $conf);

$conf->setEmpty('general.timezone', date_default_timezone_get());
$conf->setEmpty('general.title', t('Shared bookmarks on '). escape(index_url($_SERVER)));
RainTPL::$tpl_dir = $conf->get('resource.raintpl_tpl').'/'.$conf->get('resource.theme').'/'; // template directory
RainTPL::$cache_dir = $conf->get('resource.raintpl_tmp'); // cache directory

$pluginManager = new PluginManager($conf);
$pluginManager->load($conf->get('general.enabled_plugins'));

date_default_timezone_set($conf->get('general.timezone', 'UTC'));

ob_start();  // Output buffering for the page cache.

// Prevent caching on client side or proxy: (yes, it's ugly)
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

if (! is_file($conf->getConfigFileExt())) {
    // Ensure Shaarli has proper access to its resources
    $errors = ApplicationUtils::checkResourcePermissions($conf);

    if ($errors != array()) {
        $message = '<p>'. t('Insufficient permissions:') .'</p><ul>';

        foreach ($errors as $error) {
            $message .= '<li>'.$error.'</li>';
        }
        $message .= '</ul>';

        header('Content-Type: text/html; charset=utf-8');
        echo $message;
        exit;
    }

    // Display the installation form if no existing config is found
    install($conf, $sessionManager, $loginManager);
}

$loginManager->checkLoginState($_COOKIE, $clientIpId);

// ------------------------------------------------------------------------------------------
// Process login form: Check if login/password is correct.
if (isset($_POST['login'])) {
    if (! $loginManager->canLogin($_SERVER)) {
        die(t('I said: NO. You are banned for the moment. Go away.'));
    }
    if (isset($_POST['password'])
        && $sessionManager->checkToken($_POST['token'])
        && $loginManager->checkCredentials($_SERVER['REMOTE_ADDR'], $clientIpId, $_POST['login'], $_POST['password'])
    ) {
        $loginManager->handleSuccessfulLogin($_SERVER);

        $cookiedir = '';
        if (dirname($_SERVER['SCRIPT_NAME']) != '/') {
            // Note: Never forget the trailing slash on the cookie path!
            $cookiedir = dirname($_SERVER["SCRIPT_NAME"]) . '/';
        }

        if (!empty($_POST['longlastingsession'])) {
            // Keep the session cookie even after the browser closes
            $sessionManager->setStaySignedIn(true);
            $expirationTime = $sessionManager->extendSession();

            setcookie(
                $loginManager::$STAY_SIGNED_IN_COOKIE,
                $loginManager->getStaySignedInToken(),
                $expirationTime,
                WEB_PATH
            );
        } else {
            // Standard session expiration (=when browser closes)
            $expirationTime = 0;
        }

        // Send cookie with the new expiration date to the browser
        session_destroy();
        session_set_cookie_params($expirationTime, $cookiedir, $_SERVER['SERVER_NAME']);
        session_start();
        session_regenerate_id(true);

        // Optional redirect after login:
        if (isset($_GET['post'])) {
            $uri = './?post='. urlencode($_GET['post']);
            foreach (array('description', 'source', 'title', 'tags') as $param) {
                if (!empty($_GET[$param])) {
                    $uri .= '&'.$param.'='.urlencode($_GET[$param]);
                }
            }
            header('Location: '. $uri);
            exit;
        }

        if (isset($_GET['edit_link'])) {
            header('Location: ./?edit_link='. escape($_GET['edit_link']));
            exit;
        }

        if (isset($_POST['returnurl'])) {
            // Prevent loops over login screen.
            if (strpos($_POST['returnurl'], '/login') === false) {
                header('Location: '. generateLocation($_POST['returnurl'], $_SERVER['HTTP_HOST']));
                exit;
            }
        }
        header('Location: ./?');
        exit;
    } else {
        $loginManager->handleFailedLogin($_SERVER);
        $redir = '?username='. urlencode($_POST['login']);
        if (isset($_GET['post'])) {
            $redir .= '&post=' . urlencode($_GET['post']);
            foreach (array('description', 'source', 'title', 'tags') as $param) {
                if (!empty($_GET[$param])) {
                    $redir .= '&' . $param . '=' . urlencode($_GET[$param]);
                }
            }
        }
        // Redirect to login screen.
        echo '<script>alert("'. t("Wrong login/password.") .'");document.location=\'./login'.$redir.'\';</script>';
        exit;
    }
}

// ------------------------------------------------------------------------------------------
// Token management for XSRF protection
// Token should be used in any form which acts on data (create,update,delete,import...).
if (!isset($_SESSION['tokens'])) {
    $_SESSION['tokens']=array();  // Token are attached to the session.
}

/**
 * Installation
 * This function should NEVER be called if the file data/config.php exists.
 *
 * @param ConfigManager  $conf           Configuration Manager instance.
 * @param SessionManager $sessionManager SessionManager instance
 * @param LoginManager   $loginManager   LoginManager instance
 */
function install($conf, $sessionManager, $loginManager)
{
    // On free.fr host, make sure the /sessions directory exists, otherwise login will not work.
    if (endsWith($_SERVER['HTTP_HOST'], '.free.fr') && !is_dir($_SERVER['DOCUMENT_ROOT'].'/sessions')) {
        mkdir($_SERVER['DOCUMENT_ROOT'].'/sessions', 0705);
    }


    // This part makes sure sessions works correctly.
    // (Because on some hosts, session.save_path may not be set correctly,
    // or we may not have write access to it.)
    if (isset($_GET['test_session'])
        && ( !isset($_SESSION) || !isset($_SESSION['session_tested']) || $_SESSION['session_tested']!='Working')) {
        // Step 2: Check if data in session is correct.
        $msg = t(
            '<pre>Sessions do not seem to work correctly on your server.<br>'.
            'Make sure the variable "session.save_path" is set correctly in your PHP config, '.
            'and that you have write access to it.<br>'.
            'It currently points to %s.<br>'.
            'On some browsers, accessing your server via a hostname like \'localhost\' '.
            'or any custom hostname without a dot causes cookie storage to fail. '.
            'We recommend accessing your server via it\'s IP address or Fully Qualified Domain Name.<br>'
        );
        $msg = sprintf($msg, session_save_path());
        echo $msg;
        echo '<br><a href="?">'. t('Click to try again.') .'</a></pre>';
        die;
    }
    if (!isset($_SESSION['session_tested'])) {
        // Step 1 : Try to store data in session and reload page.
        $_SESSION['session_tested'] = 'Working';  // Try to set a variable in session.
        header('Location: '.index_url($_SERVER).'?test_session');  // Redirect to check stored data.
    }
    if (isset($_GET['test_session'])) {
        // Step 3: Sessions are OK. Remove test parameter from URL.
        header('Location: '.index_url($_SERVER));
    }


    if (!empty($_POST['setlogin']) && !empty($_POST['setpassword'])) {
        $tz = 'UTC';
        if (!empty($_POST['continent']) && !empty($_POST['city'])
            && isTimeZoneValid($_POST['continent'], $_POST['city'])
        ) {
            $tz = $_POST['continent'].'/'.$_POST['city'];
        }
        $conf->set('general.timezone', $tz);
        $login = $_POST['setlogin'];
        $conf->set('credentials.login', $login);
        $salt = sha1(uniqid('', true) .'_'. mt_rand());
        $conf->set('credentials.salt', $salt);
        $conf->set('credentials.hash', sha1($_POST['setpassword'] . $login . $salt));
        if (!empty($_POST['title'])) {
            $conf->set('general.title', escape($_POST['title']));
        } else {
            $conf->set('general.title', 'Shared bookmarks on '.escape(index_url($_SERVER)));
        }
        $conf->set('translation.language', escape($_POST['language']));
        $conf->set('updates.check_updates', !empty($_POST['updateCheck']));
        $conf->set('api.enabled', !empty($_POST['enableApi']));
        $conf->set(
            'api.secret',
            generate_api_secret(
                $conf->get('credentials.login'),
                $conf->get('credentials.salt')
            )
        );
        try {
            // Everything is ok, let's create config file.
            $conf->write($loginManager->isLoggedIn());
        } catch (Exception $e) {
            error_log(
                'ERROR while writing config file after installation.' . PHP_EOL .
                    $e->getMessage()
            );

            // TODO: do not handle exceptions/errors in JS.
            echo '<script>alert("'. $e->getMessage() .'");document.location=\'?\';</script>';
            exit;
        }

        $history = new History($conf->get('resource.history'));
        $bookmarkService = new BookmarkFileService($conf, $history, true);
        if ($bookmarkService->count() === 0) {
            $bookmarkService->initialize();
        }

        echo '<script>alert('
            .'"Shaarli is now configured. '
            .'Please enter your login/password and start shaaring your bookmarks!"'
            .');document.location=\'./login\';</script>';
        exit;
    }

    $PAGE = new PageBuilder($conf, $_SESSION, null, $sessionManager->generateToken());
    list($continents, $cities) = generateTimeZoneData(timezone_identifiers_list(), date_default_timezone_get());
    $PAGE->assign('continents', $continents);
    $PAGE->assign('cities', $cities);
    $PAGE->assign('languages', Languages::getAvailableLanguages());
    $PAGE->renderPage('install');
    exit;
}

if (!isset($_SESSION['LINKS_PER_PAGE'])) {
    $_SESSION['LINKS_PER_PAGE'] = $conf->get('general.links_per_page', 20);
}

$containerBuilder = new ContainerBuilder($conf, $sessionManager, $loginManager);
$container = $containerBuilder->build();
$app = new App($container);

// REST API routes
$app->group('/api/v1', function () {
    $this->get('/info', '\Shaarli\Api\Controllers\Info:getInfo')->setName('getInfo');
    $this->get('/links', '\Shaarli\Api\Controllers\Links:getLinks')->setName('getLinks');
    $this->get('/links/{id:[\d]+}', '\Shaarli\Api\Controllers\Links:getLink')->setName('getLink');
    $this->post('/links', '\Shaarli\Api\Controllers\Links:postLink')->setName('postLink');
    $this->put('/links/{id:[\d]+}', '\Shaarli\Api\Controllers\Links:putLink')->setName('putLink');
    $this->delete('/links/{id:[\d]+}', '\Shaarli\Api\Controllers\Links:deleteLink')->setName('deleteLink');

    $this->get('/tags', '\Shaarli\Api\Controllers\Tags:getTags')->setName('getTags');
    $this->get('/tags/{tagName:[\w]+}', '\Shaarli\Api\Controllers\Tags:getTag')->setName('getTag');
    $this->put('/tags/{tagName:[\w]+}', '\Shaarli\Api\Controllers\Tags:putTag')->setName('putTag');
    $this->delete('/tags/{tagName:[\w]+}', '\Shaarli\Api\Controllers\Tags:deleteTag')->setName('deleteTag');

    $this->get('/history', '\Shaarli\Api\Controllers\HistoryController:getHistory')->setName('getHistory');
})->add('\Shaarli\Api\ApiMiddleware');

$app->group('', function () {
    /* -- PUBLIC --*/
    $this->get('/', '\Shaarli\Front\Controller\Visitor\BookmarkListController:index');
    $this->get('/shaare/{hash}', '\Shaarli\Front\Controller\Visitor\BookmarkListController:permalink');
    $this->get('/login', '\Shaarli\Front\Controller\Visitor\LoginController:index')->setName('login');
    $this->get('/picture-wall', '\Shaarli\Front\Controller\Visitor\PictureWallController:index');
    $this->get('/tags/cloud', '\Shaarli\Front\Controller\Visitor\TagCloudController:cloud');
    $this->get('/tags/list', '\Shaarli\Front\Controller\Visitor\TagCloudController:list');
    $this->get('/daily', '\Shaarli\Front\Controller\Visitor\DailyController:index');
    $this->get('/daily-rss', '\Shaarli\Front\Controller\Visitor\DailyController:rss')->setName('rss');
    $this->get('/feed/atom', '\Shaarli\Front\Controller\Visitor\FeedController:atom')->setName('atom');
    $this->get('/feed/rss', '\Shaarli\Front\Controller\Visitor\FeedController:rss');
    $this->get('/open-search', '\Shaarli\Front\Controller\Visitor\OpenSearchController:index');

    $this->get('/add-tag/{newTag}', '\Shaarli\Front\Controller\Visitor\TagController:addTag');
    $this->get('/remove-tag/{tag}', '\Shaarli\Front\Controller\Visitor\TagController:removeTag');

    /* -- LOGGED IN -- */
    $this->get('/logout', '\Shaarli\Front\Controller\Admin\LogoutController:index');
    $this->get('/admin/tools', '\Shaarli\Front\Controller\Admin\ToolsController:index');
    $this->get('/admin/password', '\Shaarli\Front\Controller\Admin\PasswordController:index');
    $this->post('/admin/password', '\Shaarli\Front\Controller\Admin\PasswordController:change');
    $this->get('/admin/configure', '\Shaarli\Front\Controller\Admin\ConfigureController:index');
    $this->post('/admin/configure', '\Shaarli\Front\Controller\Admin\ConfigureController:save');
    $this->get('/admin/tags', '\Shaarli\Front\Controller\Admin\ManageTagController:index');
    $this->post('/admin/tags', '\Shaarli\Front\Controller\Admin\ManageTagController:save');
    $this->get('/admin/add-shaare', '\Shaarli\Front\Controller\Admin\ManageShaareController:addShaare');
    $this->get('/admin/shaare', '\Shaarli\Front\Controller\Admin\ManageShaareController:displayCreateForm');
    $this->get('/admin/shaare/{id:[0-9]+}', '\Shaarli\Front\Controller\Admin\ManageShaareController:displayEditForm');
    $this->post('/admin/shaare', '\Shaarli\Front\Controller\Admin\ManageShaareController:save');
    $this->get('/admin/shaare/delete', '\Shaarli\Front\Controller\Admin\ManageShaareController:deleteBookmark');
    $this->get('/admin/shaare/visibility', '\Shaarli\Front\Controller\Admin\ManageShaareController:changeVisibility');
    $this->get('/admin/shaare/{id:[0-9]+}/pin', '\Shaarli\Front\Controller\Admin\ManageShaareController:pinBookmark');
    $this->patch(
        '/admin/shaare/{id:[0-9]+}/update-thumbnail',
        '\Shaarli\Front\Controller\Admin\ThumbnailsController:ajaxUpdate'
    );
    $this->get('/admin/export', '\Shaarli\Front\Controller\Admin\ExportController:index');
    $this->post('/admin/export', '\Shaarli\Front\Controller\Admin\ExportController:export');
    $this->get('/admin/import', '\Shaarli\Front\Controller\Admin\ImportController:index');
    $this->post('/admin/import', '\Shaarli\Front\Controller\Admin\ImportController:import');
    $this->get('/admin/plugins', '\Shaarli\Front\Controller\Admin\PluginsController:index');
    $this->post('/admin/plugins', '\Shaarli\Front\Controller\Admin\PluginsController:save');
    $this->get('/admin/token', '\Shaarli\Front\Controller\Admin\TokenController:getToken');
    $this->get('/admin/thumbnails', '\Shaarli\Front\Controller\Admin\ThumbnailsController:index');

    $this->get('/links-per-page', '\Shaarli\Front\Controller\Admin\SessionFilterController:linksPerPage');
    $this->get('/visibility/{visibility}', '\Shaarli\Front\Controller\Admin\SessionFilterController:visibility');
    $this->get('/untagged-only', '\Shaarli\Front\Controller\Admin\SessionFilterController:untaggedOnly');
})->add('\Shaarli\Front\ShaarliMiddleware');

$response = $app->run(true);

$app->respond($response);

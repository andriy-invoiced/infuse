<?php

/**
 * @author Jared King <j@jaredtking.com>
 *
 * @link http://jaredtking.com
 *
 * @copyright 2015 Jared King
 * @license MIT
 */
namespace Infuse;

use Pimple\Container;

if (!defined('INFUSE_BASE_DIR')) {
    die('INFUSE_BASE_DIR has not been defined!');
}

class Application extends Container
{
    protected static $baseConfig = [
        'site' => [
            'ssl' => false,
            'port' => 80,
            'production-level' => false,
            'environment' => 'development',
            'language' => 'en',
        ],
        'services' => [
            // these services are required but can be overriden
            'errors' => 'Infuse\Services\ErrorStack',
            'locale' => 'Infuse\Services\Locale',
            'logger' => 'Infuse\Services\Logger',
            'router' => 'Infuse\Services\Router',
            'view_engine' => 'Infuse\Services\ViewEngine',
        ],
        'sessions' => [
            'enabled' => false,
        ],
        'dirs' => [
            'app' => INFUSE_BASE_DIR.'/app',
            'assets' => INFUSE_BASE_DIR.'/assets',
            'public' => INFUSE_BASE_DIR.'/public',
            'temp' => INFUSE_BASE_DIR.'/temp',
            'views' => INFUSE_BASE_DIR.'/views',
        ],
        'console' => [
            'commands' => [],
        ],
    ];

    public function __construct(array $settings = [])
    {
        parent::__construct();

        /* Load Configuration */

        $settings = array_replace_recursive(static::$baseConfig, $settings);

        $config = new Config($settings);
        $this['config'] = $config;

        /* Error Reporting */

        ini_set('display_errors', !$config->get('site.production-level'));
        ini_set('log_errors', 1);
        error_reporting(E_ALL | E_STRICT);

        /* Time Zone */

        if ($tz = $config->get('site.time-zone')) {
            date_default_timezone_set($tz);
        }

        /* Services  */

        foreach ($config->get('services') as $name => $class) {
            $this[$name] = new $class($this);
        }

        /* Base URL */

        $this['base_url'] = function () use ($config) {
            $url = (($config->get('site.ssl')) ? 'https' : 'http').'://';
            $url .= $config->get('site.hostname');
            $port = $config->get('site.port');
            $url .= ((!in_array($port, [0, 80, 443])) ? ':'.$port : '').'/';

            return $url;
        };
    }

    ////////////////////////
    // ROUTING
    ////////////////////////

    /**
     * Adds a handler to the routing table for a given GET route.
     *
     * @param string $route   path pattern
     * @param mixed  $handler route handler
     *
     * @return self
     */
    public function get($route, $handler)
    {
        $this['router']->get($route, $handler);

        return $this;
    }

    /**
     * Adds a handler to the routing table for a given POST route.
     *
     * @param string $route   path pattern
     * @param mixed  $handler route handler
     *
     * @return self
     */
    public function post($route, $handler)
    {
        $this['router']->post($route, $handler);

        return $this;
    }

    /**
     * Adds a handler to the routing table for a given PUT route.
     *
     * @param string $route   path pattern
     * @param mixed  $handler route handler
     *
     * @return self
     */
    public function put($route, $handler)
    {
        $this['router']->put($route, $handler);

        return $this;
    }

    /**
     * Adds a handler to the routing table for a given DELETE route.
     *
     * @param string $route   path pattern
     * @param mixed  $handler route handler
     *
     * @return self
     */
    public function delete($route, $handler)
    {
        $this['router']->delete($route, $handler);

        return $this;
    }

    /**
     * Adds a handler to the routing table for a given PATCH route.
     *
     * @param string $route   path pattern
     * @param mixed  $handler route handler
     *
     * @return self
     */
    public function patch($route, $handler)
    {
        $this['router']->patch($route, $handler);

        return $this;
    }

    /**
     * Adds a handler to the routing table for a given OPTIONS route.
     *
     * @param string $route   path pattern
     * @param mixed  $handler route handler
     *
     * @return self
     */
    public function options($route, $handler)
    {
        $this['router']->options($route, $handler);

        return $this;
    }

    /**
     * Adds a handler to the routing table for a given route.
     *
     * @param string $method  HTTP method
     * @param string $route   path pattern
     * @param mixed  $handler route handler
     *
     * @return self
     */
    public function map($method, $route, $handler)
    {
        $this['router']->map($method, $route, $handler);

        return $this;
    }

    ////////////////////////
    // REQUESTS
    ////////////////////////

    /**
     * Runs the application.
     *
     * @return self
     */
    public function run()
    {
        $req = Request::createFromGlobals();

        $this->handleRequest($req)->send();

        return $this;
    }

    /**
     * Builds a response to an incoming request by routing
     * it through the application.
     *
     * @param Request $req
     *
     * @return Response
     */
    public function handleRequest(Request $req)
    {
        // set host name from request if not already set
        $config = $this['config'];
        if (!$config->get('site.hostname')) {
            $config->set('site.hostname', $req->host());
        }

        // start a new session
        $this->startSession($req);

        // create a blank response
        $res = new Response();

        // middleware
        $this->executeMiddleware($req, $res);

        // route the request
        $routed = $this['router']->route($this, $req, $res);

        // HTML Error Pages for 4xx and 5xx responses
        $code = $res->getCode();
        if ($req->isHtml() && $code >= 400) {
            $body = $res->getBody();
            if (empty($body)) {
                $res->render(new View('error', [
                    'message' => Response::$codes[$code],
                    'code' => $code,
                    'title' => $code, ]));
            }
        }

        return $res;
    }

    /**
     * Starts a session.
     *
     * @param Request $req
     *
     * @return self
     */
    public function startSession(Request $req)
    {
        $config = $this['config'];
        if (!$config->get('sessions.enabled') || $req->isApi()) {
            return $this;
        }

        $lifetime = $config->get('sessions.lifetime');
        $hostname = $config->get('site.hostname');
        ini_set('session.use_trans_sid', false);
        ini_set('session.use_only_cookies', true);
        ini_set('url_rewriter.tags', '');
        ini_set('session.gc_maxlifetime', $lifetime);

        // set the session name
        $sessionTitle = $config->get('site.title').'-'.$hostname;
        $safeSessionTitle = str_replace(['.', ' ', "'", '"'], ['', '_', '', ''], $sessionTitle);
        session_name($safeSessionTitle);

        // set the session cookie parameters
        session_set_cookie_params(
            $lifetime, // lifetime
            '/', // path
            '.'.$hostname, // domain
            $req->isSecure(), // secure
            true // http only
        );

        // install any custom session handlers
        $class = $config->get('sessions.driver');
        if ($class) {
            $handler = new $class($this);
            $handler::registerHandler($handler);
        }

        session_start();

        // fix the session cookie
        Utility::set_cookie_fix_domain(
            session_name(),
            session_id(),
            time() + $lifetime,
            '/',
            $hostname,
            $req->isSecure(),
            true
        );

        // make the newly started session in our request
        $req->setSession($_SESSION);

        return $this;
    }

    /**
     * Gets the middleware modules.
     *
     * @return array
     */
    public function getMiddleware()
    {
        return (array) $this['config']->get('modules.middleware');
    }

    /**
     * Executes the middleware.
     *
     * @param Request  $req
     * @param Response $res
     *
     * @return self
     */
    public function executeMiddleware(Request $req, Response $res)
    {
        foreach ($this->getMiddleware() as $module) {
            $class = 'App\\'.$module.'\Controller';
            $controller = new $class();
            if (method_exists($controller, 'injectApp')) {
                $controller->injectApp($this);
            }
            $controller->middleware($req, $res);
        }

        return $this;
    }

    ////////////////////////
    // CONSOLE
    ////////////////////////

    /**
     * Gets a console instance for this application.
     *
     * @return \Infuse\Console\Application
     */
    public function getConsole()
    {
        return new Console\Application($this);
    }

    ////////////////////////
    // Magic Methods
    ////////////////////////

    public function __get($k)
    {
        return $this[$k];
    }

    public function __isset($k)
    {
        return isset($this[$k]);
    }
}
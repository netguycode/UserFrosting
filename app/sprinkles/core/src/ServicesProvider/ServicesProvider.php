<?php
/**
 * UserFrosting (http://www.userfrosting.com)
 *
 * @link      https://github.com/userfrosting/UserFrosting
 * @license   https://github.com/userfrosting/UserFrosting/blob/master/licenses/UserFrosting.md (MIT License)
 */
namespace UserFrosting\Sprinkle\Core\ServicesProvider;

use Dotenv\Dotenv;
use Dotenv\Exception\InvalidPathException;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Session\FileSessionHandler;
use Interop\Container\ContainerInterface;
use League\FactoryMuffin\FactoryMuffin;
use League\FactoryMuffin\Faker\Facade as Faker;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Slim\Csrf\Guard;
use Slim\Http\Uri;
use Slim\Views\Twig;
use Slim\Views\TwigExtension;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\NullSessionHandler;
use UserFrosting\Assets\AssetBundleSchema;
use UserFrosting\Cache\TaggableFileStore;
use UserFrosting\Cache\MemcachedStore;
use UserFrosting\Cache\RedisStore;
use UserFrosting\Config\ConfigPathBuilder;
use UserFrosting\I18n\LocalePathBuilder;
use UserFrosting\Assets\Assets;
use UserFrosting\Assets\PathTransformer\PrefixTransformer;
use UserFrosting\Assets\AssetBundles\GulpBundleAssetsCompiledBundles as CompiledAssetBundles;
use UserFrosting\Sprinkle\Core\Util\RawAssetBundles;
use UserFrosting\I18n\MessageTranslator;
use UserFrosting\Session\Session;
use UserFrosting\Sprinkle\Core\Error\ExceptionHandlerManager;
use UserFrosting\Sprinkle\Core\Error\Handler\NotFoundExceptionHandler;
use UserFrosting\Sprinkle\Core\Log\MixedFormatter;
use UserFrosting\Sprinkle\Core\Mail\Mailer;
use UserFrosting\Sprinkle\Core\Alert\CacheAlertStream;
use UserFrosting\Sprinkle\Core\Alert\SessionAlertStream;
use UserFrosting\Sprinkle\Core\Database\Migrator\Migrator;
use UserFrosting\Sprinkle\Core\Database\Migrator\MigrationLocator;
use UserFrosting\Sprinkle\Core\Database\Migrator\DatabaseMigrationRepository;
use UserFrosting\Sprinkle\Core\Router;
use UserFrosting\Sprinkle\Core\Throttle\Throttler;
use UserFrosting\Sprinkle\Core\Throttle\ThrottleRule;
use UserFrosting\Sprinkle\Core\Twig\CoreExtension;
use UserFrosting\Sprinkle\Core\Util\CheckEnvironment;
use UserFrosting\Sprinkle\Core\Util\ClassMapper;
use UserFrosting\Sprinkle\Core\Util\AssetLoader;
use UserFrosting\Support\Exception\BadRequestException;
use UserFrosting\Support\Exception\NotFoundException;
use UserFrosting\Support\Repository\Loader\ArrayFileLoader;
use UserFrosting\Support\Repository\Repository;

/**
 * UserFrosting core services provider.
 *
 * Registers core services for UserFrosting, such as config, database, asset manager, translator, etc.
 * @author Alex Weissman (https://alexanderweissman.com)
 */
class ServicesProvider
{
    /**
     * Register UserFrosting's core services.
     *
     * @param ContainerInterface $container A DI container implementing ArrayAccess and container-interop.
     */
    public function register(ContainerInterface $container)
    {
        /**
         * Flash messaging service.
         *
         * Persists error/success messages between requests in the session.
         */
        $container['alerts'] = function ($c) {
            $config = $c->config;

            if ($config['alert.storage'] == 'cache') {
                return new CacheAlertStream($config['alert.key'], $c->translator, $c->cache, $c->config);
            } elseif ($config['alert.storage'] == 'session') {
                return new SessionAlertStream($config['alert.key'], $c->translator, $c->session);
            } else {
                throw new \Exception("Bad alert storage handler type '{$config['alert.storage']}' specified in configuration file.");
            }
        };

        /**
         * Asset loader service
         *
         * Loads assets from a specified relative location.
         * Assets are Javascript, CSS, image, and other files used by your site.
         * This implementation is a temporary hack until Assets can be refactored.
         */
        $container['assetLoader'] = function ($c) {
            $config = $c->config;
            $locator = $c->locator;

            $baseUrl = $config['site.uri.public'] . '/' . $config['assets.raw.path'];

            $sprinkles = $c->sprinkleManager->getSprinkleNames();

            $prefixTransformer = new PrefixTransformer();
            $prefixTransformer->define(\UserFrosting\BOWER_ASSET_DIR, 'vendor-bower');
            $prefixTransformer->define(\UserFrosting\NPM_ASSET_DIR, 'vendor-npm');

            foreach ($sprinkles as $sprinkle) {
                $prefixTransformer->define(\UserFrosting\APP_DIR_NAME . \UserFrosting\DS . \UserFrosting\SPRINKLES_DIR_NAME . \UserFrosting\DS . $sprinkle . \UserFrosting\DS . \UserFrosting\ASSET_DIR_NAME, \UserFrosting\SPRINKLES_DIR_NAME . \UserFrosting\DS . $sprinkle);
            }

            $assets = new Assets($locator, 'assets', $baseUrl, $prefixTransformer);

            return new AssetLoader($assets);
        };

        /**
         * Asset manager service.
         *
         * Loads raw or compiled asset information from your bundle.config.json schema file.
         * Assets are Javascript, CSS, image, and other files used by your site.
         */
        $container['assets'] = function ($c) {
            $config = $c->config;
            $locator = $c->locator;

            // Load asset schema
            if ($config['assets.use_raw']) {
                $baseUrl = $config['site.uri.public'] . '/' . $config['assets.raw.path'];

                $sprinkles = $c->sprinkleManager->getSprinkleNames();

                $prefixTransformer = new PrefixTransformer();
                $prefixTransformer->define(\UserFrosting\BOWER_ASSET_DIR, 'vendor-bower');
                $prefixTransformer->define(\UserFrosting\NPM_ASSET_DIR, 'vendor-npm');

                foreach ($sprinkles as $sprinkle) {
                    $prefixTransformer->define(\UserFrosting\APP_DIR_NAME . \UserFrosting\DS . \UserFrosting\SPRINKLES_DIR_NAME . \UserFrosting\DS . $sprinkle . \UserFrosting\DS . \UserFrosting\ASSET_DIR_NAME, \UserFrosting\SPRINKLES_DIR_NAME . \UserFrosting\DS . $sprinkle);
                }
                $assets = new Assets($locator, 'assets', $baseUrl, $prefixTransformer);

                // Load raw asset bundles for each Sprinkle.

                // Retrieve locations of raw asset bundle schemas that exist.
                $bundleSchemas = array_reverse($locator->findResources('sprinkles://' . $config['assets.raw.schema']));

                // Load asset bundle schemas that exist.
                if (array_key_exists(0, $bundleSchemas)) {
                    $bundles = new RawAssetBundles(array_shift($bundleSchemas));

                    foreach ($bundleSchemas as $bundleSchema) {
                        $bundles->extend($bundleSchema);
                    }

                    // Add bundles to asset manager.
                    $assets->addAssetBundles($bundles);
                }
            } else {
                $baseUrl = $config['site.uri.public'] . '/' . $config['assets.compiled.path'];
                $assets = new Assets($locator, 'assets', $baseUrl);
                $assets->overrideBasePath($locator->getBase() . '/public/assets');

                // Load compiled asset bundle.
                $assets->addAssetBundles(new CompiledAssetBundles($locator("build://" . $config['assets.compiled.schema'], true, true)));
            }

            return $assets;
        };

        /**
         * Cache service.
         *
         * @return \Illuminate\Cache\Repository
         */
        $container['cache'] = function ($c) {

            $config = $c->config;

            if ($config['cache.driver'] == 'file') {
                $path = $c->locator->findResource('cache://', true, true);
                $cacheStore = new TaggableFileStore($path);
            } elseif ($config['cache.driver'] == 'memcached') {
                // We need to inject the prefix in the memcached config
                $config = array_merge($config['cache.memcached'], ['prefix' => $config['cache.prefix']]);
                $cacheStore = new MemcachedStore($config);
            } elseif ($config['cache.driver'] == 'redis') {
                // We need to inject the prefix in the redis config
                $config = array_merge($config['cache.redis'], ['prefix' => $config['cache.prefix']]);
                $cacheStore = new RedisStore($config);
            } else {
                throw new \Exception("Bad cache store type '{$config['cache.driver']}' specified in configuration file.");
            }

            return $cacheStore->instance();
        };

        /**
         * Middleware to check environment.
         *
         * @todo We should cache the results of this, the first time that it succeeds.
         */
        $container['checkEnvironment'] = function ($c) {
            $checkEnvironment = new CheckEnvironment($c->view, $c->locator, $c->cache);
            return $checkEnvironment;
        };

        /**
         * Class mapper.
         *
         * Creates an abstraction on top of class names to allow extending them in sprinkles.
         */
        $container['classMapper'] = function ($c) {
            $classMapper = new ClassMapper();
            $classMapper->setClassMapping('query_builder', 'UserFrosting\Sprinkle\Core\Database\Builder');
            $classMapper->setClassMapping('eloquent_builder', 'UserFrosting\Sprinkle\Core\Database\EloquentBuilder');
            $classMapper->setClassMapping('throttle', 'UserFrosting\Sprinkle\Core\Database\Models\Throttle');
            return $classMapper;
        };

        /**
         * Site config service (separate from Slim settings).
         *
         * Will attempt to automatically determine which config file(s) to use based on the value of the UF_MODE environment variable.
         */
        $container['config'] = function ($c) {
            // Grab any relevant dotenv variables from the .env file
            try {
                $dotenv = new Dotenv(\UserFrosting\APP_DIR);
                $dotenv->load();
            } catch (InvalidPathException $e) {
                // Skip loading the environment config file if it doesn't exist.
            }

            // Get configuration mode from environment
            $mode = getenv('UF_MODE') ?: '';

            // Construct and load config repository
            $builder = new ConfigPathBuilder($c->locator, 'config://');
            $loader = new ArrayFileLoader($builder->buildPaths($mode));
            $config = new Repository($loader->load());

            // Construct base url from components, if not explicitly specified
            if (!isset($config['site.uri.public'])) {
                $uri = $c->request->getUri();

                // Slim\Http\Uri likes to add trailing slashes when the path is empty, so this fixes that.
                $config['site.uri.public'] = trim($uri->getBaseUrl(), '/');
            }

            // Hacky fix to prevent sessions from being hit too much: ignore CSRF middleware for requests for raw assets ;-)
            // See https://github.com/laravel/framework/issues/8172#issuecomment-99112012 for more information on why it's bad to hit Laravel sessions multiple times in rapid succession.
            $csrfBlacklist = $config['csrf.blacklist'];
            $csrfBlacklist['^/' . $config['assets.raw.path']] = [
                'GET'
            ];

            $config->set('csrf.blacklist', $csrfBlacklist);

            // Reset 'assets' scheme in locator if specified in config. (must be done here thanks to prevent circular dependency)
            if (!$config['assets.use_raw']) {
                $c->locator->removeStream('assets');
                $c->locator->registerStream('assets', \UserFrosting\PUBLIC_DIR_NAME . '/' . \UserFrosting\ASSET_DIR_NAME, true);
            }

            return $config;
        };

        /**
         * Initialize CSRF guard middleware.
         *
         * @see https://github.com/slimphp/Slim-Csrf
         */
        $container['csrf'] = function ($c) {
            $csrfKey = $c->config['session.keys.csrf'];

            // Workaround so that we can pass storage into CSRF guard.
            // If we tried to directly pass the indexed portion of `session` (for example, $c->session['site.csrf']),
            // we would get an 'Indirect modification of overloaded element of UserFrosting\Session\Session' error.
            // If we tried to assign an array and use that, PHP would only modify the local variable, and not the session.
            // Since ArrayObject is an object, PHP will modify the object itself, allowing it to persist in the session.
            if (!$c->session->has($csrfKey)) {
                $c->session[$csrfKey] = new \ArrayObject();
            }
            $csrfStorage = $c->session[$csrfKey];

            $onFailure = function ($request, $response, $next) {
                $e = new BadRequestException("The CSRF code was invalid or not provided.");
                $e->addUserMessage('CSRF_MISSING');
                throw $e;

                return $next($request, $response);
            };

            return new Guard($c->config['csrf.name'], $csrfStorage, $onFailure, $c->config['csrf.storage_limit'], $c->config['csrf.strength'], $c->config['csrf.persistent_token']);
        };

        /**
         * Initialize Eloquent Capsule, which provides the database layer for UF.
         *
         * @todo construct the individual objects rather than using the facade
         */
        $container['db'] = function ($c) {
            $config = $c->config;

            $capsule = new Capsule;

            foreach ($config['db'] as $name => $dbConfig) {
                $capsule->addConnection($dbConfig, $name);
            }

            $queryEventDispatcher = new Dispatcher(new Container);

            $capsule->setEventDispatcher($queryEventDispatcher);

            // Register as global connection
            $capsule->setAsGlobal();

            // Start Eloquent
            $capsule->bootEloquent();

            if ($config['debug.queries']) {
                $logger = $c->queryLogger;

                foreach ($config['db'] as $name => $dbConfig) {
                    $capsule->connection($name)->enableQueryLog();
                }

                // Register listener
                $queryEventDispatcher->listen(QueryExecuted::class, function ($query) use ($logger) {
                    $logger->debug("Query executed on database [{$query->connectionName}]:", [
                        'query'    => $query->sql,
                        'bindings' => $query->bindings,
                        'time'     => $query->time . ' ms'
                    ]);
                });
            }

            return $capsule;
        };

        /**
         * Debug logging with Monolog.
         *
         * Extend this service to push additional handlers onto the 'debug' log stack.
         */
        $container['debugLogger'] = function ($c) {
            $logger = new Logger('debug');

            $logFile = $c->locator->findResource('log://userfrosting.log', true, true);

            $handler = new StreamHandler($logFile);

            $formatter = new MixedFormatter(null, null, true);

            $handler->setFormatter($formatter);
            $logger->pushHandler($handler);

            return $logger;
        };

        /**
         * Custom error-handler for recoverable errors.
         */
        $container['errorHandler'] = function ($c) {
            $settings = $c->settings;

            $handler = new ExceptionHandlerManager($c, $settings['displayErrorDetails']);

            // Register the base HttpExceptionHandler.
            $handler->registerHandler('\UserFrosting\Support\Exception\HttpException', '\UserFrosting\Sprinkle\Core\Error\Handler\HttpExceptionHandler');

            // Register the NotFoundExceptionHandler.
            $handler->registerHandler('\UserFrosting\Support\Exception\NotFoundException', '\UserFrosting\Sprinkle\Core\Error\Handler\NotFoundExceptionHandler');

            // Register the PhpMailerExceptionHandler.
            $handler->registerHandler('\phpmailerException', '\UserFrosting\Sprinkle\Core\Error\Handler\PhpMailerExceptionHandler');

            return $handler;
        };

        /**
         * Error logging with Monolog.
         *
         * Extend this service to push additional handlers onto the 'error' log stack.
         */
        $container['errorLogger'] = function ($c) {
            $log = new Logger('errors');

            $logFile = $c->locator->findResource('log://userfrosting.log', true, true);

            $handler = new StreamHandler($logFile, Logger::WARNING);

            $formatter = new LineFormatter(null, null, true);

            $handler->setFormatter($formatter);
            $log->pushHandler($handler);

            return $log;
        };

        /**
         * Factory service with FactoryMuffin.
         *
         * Provide access to factories for the rapid creation of objects for the purpose of testing
         */
        $container['factory'] = function ($c) {

            // Get the path of all of the sprinkle's factories
            $factoriesPath = $c->locator->findResources('factories://', true);

            // Create a new Factory Muffin instance
            $fm = new FactoryMuffin();

            // Load all of the model definitions
            $fm->loadFactories($factoriesPath);

            // Set the locale. Could be the config one, but for testing English should do
            Faker::setLocale('en_EN');

            return $fm;
        };

        /**
         * Builds search paths for locales in all Sprinkles.
         */
        $container['localePathBuilder'] = function ($c) {
            $config = $c->config;
            $request = $c->request;

            // Make sure the locale config is a valid string
            if (!is_string($config['site.locales.default']) || $config['site.locales.default'] == '') {
                throw new \UnexpectedValueException('The locale config is not a valid string.');
            }

            // Get default locales as specified in configurations.
            $locales = explode(',', $config['site.locales.default']);

            // Get available locales (removing null values)
            $availableLocales = array_filter($config['site.locales.available']);

            // Add supported browser preferred locales.
            if ($request->hasHeader('Accept-Language')) {
                $allowedLocales = [];
                foreach (explode(',', $request->getHeaderLine('Accept-Language')) as $index => $browserLocale) {
                    // Split to access q
                    $parts = explode(';', $browserLocale) ?: [];

                    // Ensure locale valid
                    if (array_key_exists(0, $parts)) {
                        // Format for UF's i18n
                        $parts[0] = str_replace('-', '_', $parts[0]);
                        // Ensure locale available
                        if (array_key_exists($parts[0], $availableLocales)) {
                            // Determine preference level, and add to $allowedLocales
                            if (array_key_exists(1, $parts)) {
                                $parts[1] = str_replace('q=', '', $parts[1]);
                                // Sanitize with int cast (bad values go to 0)
                                $parts[1] = (int)$parts[1];
                            } else {
                                $parts[1] = 1;
                            }
                            // Add to list, and format for UF's i18n.
                            $allowedLocales[$parts[0]] = $parts[1];
                        }
                    }
                }

                // Sort, extract keys, and merge with $locales
                asort($allowedLocales, SORT_NUMERIC);
                $locales = array_merge($locales, array_keys($allowedLocales));

                // Remove duplicates, while maintaining fallback order
                $locales = array_reverse(array_unique(array_reverse($locales), SORT_STRING));
            }

            return new LocalePathBuilder($c->locator, 'locale://', $locales);
        };

        /**
         * Mail service.
         */
        $container['mailer'] = function ($c) {
            $mailer = new Mailer($c->mailLogger, $c->config['mail']);

            // Use UF debug settings to override any service-specific log settings.
            if (!$c->config['debug.smtp']) {
                $mailer->getPhpMailer()->SMTPDebug = 0;
            }

            return $mailer;
        };

        /**
         * Mail logging service.
         *
         * PHPMailer will use this to log SMTP activity.
         * Extend this service to push additional handlers onto the 'mail' log stack.
         */
        $container['mailLogger'] = function ($c) {
            $log = new Logger('mail');

            $logFile = $c->locator->findResource('log://userfrosting.log', true, true);

            $handler = new StreamHandler($logFile);
            $formatter = new LineFormatter(null, null, true);

            $handler->setFormatter($formatter);
            $log->pushHandler($handler);

            return $log;
        };

        /**
         * Migrator service.
         *
         * This service handles database migration operations
         */
        $container['migrator'] = function ($c) {
            $migrator = new Migrator(
                $c->db,
                new DatabaseMigrationRepository($c->db, $c->config['migrations.repository_table']),
                new MigrationLocator($c->sprinkleManager, new Filesystem)
            );

            // Make sure repository exist
            if (!$migrator->repositoryExists()) {
                $migrator->getRepository()->createRepository();
            }

            return $migrator;
        };

        /**
         * Error-handler for 404 errors.  Notice that we manually create a UserFrosting NotFoundException,
         * and a NotFoundExceptionHandler.  This lets us pass through to the UF error handling system.
         */
        $container['notFoundHandler'] = function ($c) {
            return function ($request, $response) use ($c) {
                $exception = new NotFoundException;
                $handler = new NotFoundExceptionHandler($c, $request, $response, $exception, $c->settings['displayErrorDetails']);
                return $handler->handle();
            };
        };

        /**
         * Error-handler for PHP runtime errors.  Notice that we just pass this through to our general-purpose
         * error-handling service.
         */
        $container['phpErrorHandler'] = function ($c) {
            return $c->errorHandler;
        };

        /**
         * Laravel query logging with Monolog.
         *
         * Extend this service to push additional handlers onto the 'query' log stack.
         */
        $container['queryLogger'] = function ($c) {
            $logger = new Logger('query');

            $logFile = $c->locator->findResource('log://userfrosting.log', true, true);

            $handler = new StreamHandler($logFile);

            $formatter = new MixedFormatter(null, null, true);

            $handler->setFormatter($formatter);
            $logger->pushHandler($handler);

            return $logger;
        };

        /**
         * Override Slim's default router with the UF router.
         */
        $container['router'] = function ($c) {
            $routerCacheFile = false;
            if (isset($c->config['settings.routerCacheFile'])) {
                $routerCacheFile = $c->config['settings.routerCacheFile'];
            }

            return (new Router)->setCacheFile($routerCacheFile);
        };

        /**
         * Start the PHP session, with the name and parameters specified in the configuration file.
         */
        $container['session'] = function ($c) {
            $config = $c->config;

            // Create appropriate handler based on config
            if ($config['session.handler'] == 'file') {
                $fs = new Filesystem;
                $handler = new FileSessionHandler($fs, $c->locator->findResource('session://'), $config['session.minutes']);
            } elseif ($config['session.handler'] == 'database') {
                $connection = $c->db->connection();
                // Table must exist, otherwise an exception will be thrown
                $handler = new DatabaseSessionHandler($connection, $config['session.database.table'], $config['session.minutes']);
            } else {
                throw new \Exception("Bad session handler type '{$config['session.handler']}' specified in configuration file.");
            }

            // Create, start and return a new wrapper for $_SESSION
            $session = new Session($handler, $config['session']);
            $session->start();

            return $session;
        };

        /**
         * Request throttler.
         *
         * Throttles (rate-limits) requests of a predefined type, with rules defined in site config.
         */
        $container['throttler'] = function ($c) {
            $throttler = new Throttler($c->classMapper);

            $config = $c->config;

            if ($config->has('throttles') && ($config['throttles'] !== null)) {
                foreach ($config['throttles'] as $type => $rule) {
                    if ($rule) {
                        $throttleRule = new ThrottleRule($rule['method'], $rule['interval'], $rule['delays']);
                        $throttler->addThrottleRule($type, $throttleRule);
                    } else {
                        $throttler->addThrottleRule($type, null);
                    }
                }
            }

            return $throttler;
        };

        /**
         * Translation service, for translating message tokens.
         */
        $container['translator'] = function ($c) {
            // Load the translations
            $paths = $c->localePathBuilder->buildPaths();
            $loader = new ArrayFileLoader($paths);

            // Create the $translator object
            $translator = new MessageTranslator($loader->load());

            return $translator;
        };

        /**
         * Set up Twig as the view, adding template paths for all sprinkles and the Slim Twig extension.
         *
         * Also adds the UserFrosting core Twig extension, which provides additional functions, filters, global variables, etc.
         */
        $container['view'] = function ($c) {
            $templatePaths = $c->locator->findResources('templates://', true, true);

            $view = new Twig($templatePaths);

            $loader = $view->getLoader();

            $sprinkles = $c->sprinkleManager->getSprinkleNames();

            // Add Sprinkles' templates namespaces
            foreach ($sprinkles as $sprinkle) {
                $path = \UserFrosting\SPRINKLES_DIR . \UserFrosting\DS .
                    $sprinkle . \UserFrosting\DS .
                    \UserFrosting\TEMPLATE_DIR_NAME . \UserFrosting\DS;

                if (is_dir($path)) {
                    $loader->addPath($path, $sprinkle);
                }
            }

            $twig = $view->getEnvironment();

            if ($c->config['cache.twig']) {
                $twig->setCache($c->locator->findResource('cache://twig', true, true));
            }

            if ($c->config['debug.twig']) {
                $twig->enableDebug();
                $view->addExtension(new \Twig_Extension_Debug());
            }

            // Register the Slim extension with Twig
            $slimExtension = new TwigExtension(
                $c->router,
                $c->request->getUri()
            );
            $view->addExtension($slimExtension);

            // Register the core UF extension with Twig
            $coreExtension = new CoreExtension($c);
            $view->addExtension($coreExtension);

            return $view;
        };
    }
}

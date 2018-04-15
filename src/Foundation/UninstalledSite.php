<?php

/*
 * This file is part of Flarum.
 *
 * (c) Toby Zerner <toby.zerner@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Flarum\Foundation;

use Flarum\Database\DatabaseServiceProvider;
use Flarum\Database\MigrationServiceProvider;
use Flarum\Install\Installer;
use Flarum\Install\InstallServiceProvider;
use Flarum\Locale\LocaleServiceProvider;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Settings\UninstalledSettingsRepository;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Hashing\HashServiceProvider;
use Illuminate\Validation\ValidationServiceProvider;
use Illuminate\View\ViewServiceProvider;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

class UninstalledSite implements SiteInterface
{
    /**
     * @var Application
     */
    protected $laravel;

    /**
     * @var string
     */
    protected $basePath;

    /**
     * @var string
     */
    protected $publicPath;

    /**
     * @var string
     */
    protected $storagePath;

    public function __construct($basePath, $publicPath)
    {
        $this->basePath = $basePath;
        $this->publicPath = $publicPath;
    }

    /**
     * Create and boot a Flarum application instance
     *
     * @return AppInterface
     */
    public function bootApp(): AppInterface
    {
        return new Installer(
            $this->bootLaravel()
        );
    }

    private function bootLaravel(): Application
    {
        if ($this->laravel !== null) {
            return $this->laravel;
        }

        date_default_timezone_set('UTC');

        $app = new Application($this->basePath, $this->publicPath);

        if ($this->storagePath) {
            $app->useStoragePath($this->storagePath);
        }

        $app->instance('env', 'production');
        $app->instance('flarum.config', []);
        $app->instance('config', $config = $this->getIlluminateConfig($app));

        $this->registerLogger($app);

        $this->registerCache($app);

        $app->register(MigrationServiceProvider::class);
        $app->register(LocaleServiceProvider::class);
        $app->register(FilesystemServiceProvider::class);
        $app->register(HashServiceProvider::class);
        $app->register(ViewServiceProvider::class);
        $app->register(ValidationServiceProvider::class);

        $app->register(InstallServiceProvider::class);

        $app->singleton(
            SettingsRepositoryInterface::class,
            UninstalledSettingsRepository::class
        );

        $app->boot();

        $this->laravel = $app;

        return $app;
    }

    /**
     * @param Application $app
     * @return ConfigRepository
     */
    protected function getIlluminateConfig(Application $app)
    {
        return new ConfigRepository([
            'view' => [
                'paths' => [],
                'compiled' => $app->storagePath().'/views',
            ],
            'session' => [
                'lifetime' => 120,
                'files' => $app->storagePath().'/sessions',
                'cookie' => 'session'
            ]
        ]);
    }

    protected function registerLogger(Application $app)
    {
        // TODO: Write to different file, highest log level

        $logger = new Logger('Flarum Installer');
        $logPath = $app->storagePath().'/logs/flarum-installer.log';

        $handler = new StreamHandler($logPath, Logger::DEBUG);
        $handler->setFormatter(new LineFormatter(null, null, true, true));

        $logger->pushHandler($handler);

        $app->instance('log', $logger);
        $app->alias('log', LoggerInterface::class);
    }

    protected function registerCache(Application $app)
    {
        $app->singleton(Repository::class, CacheRepository::class);
        $app->singleton(Store::class, ArrayStore::class);
    }
}

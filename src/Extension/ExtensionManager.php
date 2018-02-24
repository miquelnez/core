<?php

/*
 * This file is part of Flarum.
 *
 * (c) Toby Zerner <toby.zerner@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Flarum\Extension;

use Flarum\Database\Migrator;
use Flarum\Extend\Compat;
use Flarum\Extension\Event\Disabled;
use Flarum\Extension\Event\Disabling;
use Flarum\Extension\Event\Enabled;
use Flarum\Extension\Event\Enabling;
use Flarum\Extension\Event\Uninstalled;
use Flarum\Foundation\Application;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class ExtensionManager
{
    protected $config;

    protected $app;

    protected $migrator;

    /**
     * @var Dispatcher
     */
    protected $dispatcher;

    /**
     * @var Filesystem
     */
    protected $filesystem;

    /**
     * @var Collection|null
     */
    protected $extensions;

    public function __construct(
        SettingsRepositoryInterface $config,
        Application $app,
        Migrator $migrator,
        Dispatcher $dispatcher,
        Filesystem $filesystem
    ) {
        $this->config = $config;
        $this->app = $app;
        $this->migrator = $migrator;
        $this->dispatcher = $dispatcher;
        $this->filesystem = $filesystem;
    }

    /**
     * @return Collection
     */
    public function getExtensions()
    {
        if (is_null($this->extensions) && $this->filesystem->exists($this->app->basePath().'/vendor/composer/installed.json')) {
            $extensions = new Collection();

            // Load all packages installed by composer.
            $installed = json_decode($this->filesystem->get($this->app->basePath().'/vendor/composer/installed.json'), true);

            foreach ($installed as $package) {
                if (Arr::get($package, 'type') != 'flarum-extension' || empty(Arr::get($package, 'name'))) {
                    continue;
                }
                // Instantiates an Extension object using the package path and composer.json file.
                $extension = new Extension($this->getExtensionsDir().'/'.Arr::get($package, 'name'), $package);

                // Per default all extensions are installed if they are registered in composer.
                $extension->setInstalled(true);
                $extension->setVersion(Arr::get($package, 'version'));
                $extension->setEnabled($this->isEnabled($extension->getId()));

                $extensions->put($extension->getId(), $extension);
            }
            $this->extensions = $extensions->sortBy(function ($extension, $name) {
                return $extension->composerJsonAttribute('extra.flarum-extension.title');
            });
        }

        return $this->extensions;
    }

    /**
     * Loads an Extension with all information.
     *
     * @param string $name
     * @return Extension|null
     */
    public function getExtension($name)
    {
        return $this->getExtensions()->get($name);
    }

    /**
     * Enables the extension.
     *
     * @param string $name
     */
    public function enable($name)
    {
        if (! $this->isEnabled($name)) {
            $extension = $this->getExtension($name);

            $this->dispatcher->dispatch(new Enabling($extension));

            $enabled = $this->getEnabled();

            $enabled[] = $name;

            $this->migrate($extension);

            $this->publishAssets($extension);

            $this->setEnabled($enabled);

            $extension->setEnabled(true);

            $this->dispatcher->dispatch(new Enabled($extension));
        }
    }

    /**
     * Disables an extension.
     *
     * @param string $name
     */
    public function disable($name)
    {
        $enabled = $this->getEnabled();

        if (($k = array_search($name, $enabled)) !== false) {
            $extension = $this->getExtension($name);

            $this->dispatcher->dispatch(new Disabling($extension));

            unset($enabled[$k]);

            $this->setEnabled($enabled);

            $extension->setEnabled(false);

            $this->dispatcher->dispatch(new Disabled($extension));
        }
    }

    /**
     * Uninstalls an extension.
     *
     * @param string $name
     */
    public function uninstall($name)
    {
        $extension = $this->getExtension($name);

        $this->disable($name);

        $this->migrateDown($extension);

        $this->unpublishAssets($extension);

        $extension->setInstalled(false);

        $this->dispatcher->dispatch(new Uninstalled($extension));
    }

    /**
     * Copy the assets from an extension's assets directory into public view.
     *
     * @param Extension $extension
     */
    protected function publishAssets(Extension $extension)
    {
        if ($extension->hasAssets()) {
            $this->filesystem->copyDirectory(
                $extension->getPath().'/assets',
                $this->app->publicPath().'/assets/extensions/'.$extension->getId()
            );
        }
    }

    /**
     * Delete an extension's assets from public view.
     *
     * @param Extension $extension
     */
    protected function unpublishAssets(Extension $extension)
    {
        $this->filesystem->deleteDirectory($this->app->publicPath().'/assets/extensions/'.$extension->getId());
    }

    /**
     * Get the path to an extension's published asset.
     *
     * @param Extension $extension
     * @param string    $path
     * @return string
     */
    public function getAsset(Extension $extension, $path)
    {
        return $this->app->publicPath().'/assets/extensions/'.$extension->getId().$path;
    }

    /**
     * Runs the database migrations for the extension.
     *
     * @param Extension $extension
     * @param bool|true $up
     * @return array Notes from the migrator.
     */
    public function migrate(Extension $extension, $up = true)
    {
        if ($extension->hasMigrations()) {
            $migrationDir = $extension->getPath().'/migrations';

            $this->app->bind('Illuminate\Database\Schema\Builder', function ($container) {
                return $container->make('Illuminate\Database\ConnectionInterface')->getSchemaBuilder();
            });

            if ($up) {
                $this->migrator->run($migrationDir, $extension);
            } else {
                $this->migrator->reset($migrationDir, $extension);
            }

            return $this->migrator->getNotes();
        }

        return [];
    }

    /**
     * Runs the database migrations to reset the database to its old state.
     *
     * @param Extension $extension
     */
    public function migrateDown(Extension $extension)
    {
        $this->migrate($extension, false);
    }

    /**
     * The database migrator.
     *
     * @return Migrator
     */
    public function getMigrator()
    {
        return $this->migrator;
    }

    /**
     * Get only enabled extensions.
     *
     * @return Collection
     */
    public function getEnabledExtensions()
    {
        return $this->getExtensions()->only($this->getEnabled());
    }

    /**
     * Retrieve all extender instances of all enabled extensions.
     *
     * @return Collection
     */
    public function getActiveExtenders()
    {
        return $this->getEnabledExtensions()
            ->flatMap(function (Extension $extension) {
                $bootstrapper = $extension->getBootstrapperPath();

                if ($this->filesystem->exists($bootstrapper)) {
                    return array_map(function ($extender) use ($extension) {
                        // If an extension has not yet switched to the new bootstrap.php
                        // format, it might return a function (or more of them). We wrap
                        // these in a Compat extender to enjoy an unique interface.
                        if ($extender instanceof \Closure || is_string($extender)) {
                            $extender = new Compat($extender);
                        }

                        return function ($app) use ($extension, $extender) {
                            return $extender($app, $extension);
                        };
                    }, array_flatten((array) require $bootstrapper));
                }

                return [];
            });
    }

    /**
     * The id's of the enabled extensions.
     *
     * @return array
     */
    public function getEnabled()
    {
        return json_decode($this->config->get('extensions_enabled'), true);
    }

    /**
     * Persist the currently enabled extensions.
     *
     * @param array $enabled
     */
    protected function setEnabled(array $enabled)
    {
        $enabled = array_values(array_unique($enabled));

        $this->config->set('extensions_enabled', json_encode($enabled));
    }

    /**
     * Whether the extension is enabled.
     *
     * @param $extension
     * @return bool
     */
    public function isEnabled($extension)
    {
        return in_array($extension, $this->getEnabled());
    }

    /**
     * The extensions path.
     *
     * @return string
     */
    protected function getExtensionsDir()
    {
        return $this->app->basePath().'/vendor';
    }
}

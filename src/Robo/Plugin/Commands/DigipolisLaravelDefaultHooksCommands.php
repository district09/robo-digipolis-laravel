<?php

namespace DigipolisGent\Robo\Laravel\Robo\Plugin\Commands;

use Consolidation\AnnotatedCommand\Events\CustomEventAwareInterface;
use DigipolisGent\Robo\Laravel\EventHandler\BuildTaskHandler;
use DigipolisGent\Robo\Laravel\EventHandler\ClearCacheHandler;
use DigipolisGent\Robo\Laravel\EventHandler\FileBackupConfigHandler;
use DigipolisGent\Robo\Laravel\EventHandler\InstallLaravelHandler;
use DigipolisGent\Robo\Laravel\EventHandler\InstallHandler;
use DigipolisGent\Robo\Laravel\EventHandler\IsSiteInstalledHandler;
use DigipolisGent\Robo\Laravel\EventHandler\PostSymlinkHandler;
use DigipolisGent\Robo\Laravel\EventHandler\PreRestoreBackupRemoteHandler;
use DigipolisGent\Robo\Laravel\EventHandler\UpdateLaravelHandler;
use DigipolisGent\Robo\Laravel\EventHandler\UpdateHandler;
use Dotenv\Dotenv;
use Robo\Contract\ConfigAwareInterface;
use Robo\Tasks;
use Symfony\Component\Finder\Finder;


class DigipolisLaravelDefaultHooksCommands extends Tasks implements ConfigAwareInterface, CustomEventAwareInterface
{
    use \Consolidation\Config\ConfigAwareTrait;
    use \DigipolisGent\Robo\Task\General\Common\DigipolisPropertiesAware;
    use \DigipolisGent\Robo\Task\General\Tasks;
    use \DigipolisGent\Robo\Helpers\Traits\EventDispatcher;
    use \Consolidation\AnnotatedCommand\Events\CustomEventAwareTrait;

    /**
     * @hook on-event digipolis-db-config
     */
    public function defaultDbConfig()
    {
        $this->readProperties();
        $rootDir = $this->getConfig()->get('digipolis.root.project', false);
        if (!$rootDir) {
            return false;
        }

        $finder = new Finder();
        $finder->in($rootDir)->ignoreDotFiles(false)->files()->name('.env');
        $settings = [];
        /** @var \SplFileInfo $settingsFile */
        foreach ($finder as $settingsFile) {
            $settings = Dotenv::parse(file_get_contents($settingsFile->getRealPath()));
            break;
        }
        return [
          'default' => [
                'type' => $settings['DB_CONNECTION'] ?? 'mysql',
                'host' => $settings['DB_HOST'] ?? '127.0.0.1',
                'port' => $settings['DB_PORT'] ?? '3306',
                'user' => $settings['DB_USERNAME'] ?? 'forge',
                'pass' => $settings['DB_PASSWORD'] ?? 'forge',
                'database' => $settings['DB_DATABASE'] ?? null,
                'structureTables' => [],
                'extra' => '--skip-add-locks --no-tablespaces',
            ]
        ];
    }

    /**
     * @hook on-event digipolis:build-task
     */
    public function getBuildTaskHandler()
    {
        return new BuildTaskHandler();
    }

    /**
     * @hook on-event digipolis:clear-cache
     */
    public function getClearCacheHandler()
    {
        return new ClearCacheHandler();
    }

    /**
     * @hook on-event digipolis:post-symlink
     */
    public function getPostSymlinkHandler()
    {
        return new PostSymlinkHandler();
    }

    /**
     * @hook on-event digipolis:pre-restore-backup-remote
     */
    public function getPreRestoreBackupRemoteHandler()
    {
        return new PreRestoreBackupRemoteHandler();
    }

    /**
     * @hook on-event digipolis:is-site-installed
     */
    public function getIsSiteInstalledHandler()
    {
        return new IsSiteInstalledHandler();
    }

    /**
     * @hook on-event digipolis:file-backup-config
     */
    public function getBackupConfig()
    {
        return new FileBackupConfigHandler();
    }

    /**
     * @hook on-event digipolis:install
     */
    public function getInstallHandler()
    {
        return new InstallHandler();
    }

    /**
     * @hook on-event digipolis:update
     */
    public function getUpdateHandler()
    {
        return new UpdateHandler();
    }

    /**
     * @hook on-event digipolis:install-laravel
     */
    public function getInstallLaravelHandler()
    {
        return new InstallLaravelHandler();
    }

    /**
     * @hook on-event digipolis:update-laravel
     */
    public function getUpdateLaravelHandler()
    {
        return new UpdateLaravelHandler();
    }

}

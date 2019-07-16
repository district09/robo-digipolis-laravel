<?php

namespace DigipolisGent\Robo\Laravel;

use DigipolisGent\Robo\Helpers\AbstractRoboFile;
use DigipolisGent\Robo\Task\Deploy\Ssh\Auth\AbstractAuth;
use Dotenv\Dotenv;
use Symfony\Component\Finder\Finder;

class RoboFileBase extends AbstractRoboFile
{
    use \DigipolisGent\Robo\Task\CodeValidation\loadTasks;
    use \DigipolisGent\Robo\Helpers\Traits\AbstractCommandTrait;
    use \DigipolisGent\Robo\Task\Deploy\Commands\loadCommands;
    use \DigipolisGent\Robo\Task\Package\Traits\ThemeCompileTrait;
    use \DigipolisGent\Robo\Task\Package\Traits\ThemeCleanTrait;
    use Traits\BuildLaravelTrait;
    use Traits\DeployLaravelTrait;
    use Traits\UpdateLaravelTrait;
    use Traits\InstallLaravelTrait;
    use Traits\SyncLaravelTrait;

    /**
     * File backup subdirs.
     *
     * @var type
     */
    protected $fileBackupSubDirs = ['storage'];

    protected $excludeFromBackup = ['storage/logs/*'];

    /**
     * {@inheritdoc}
     */
    protected function isSiteInstalled($worker, AbstractAuth $auth, $remote)
    {
        $currentProjectRoot = $remote['currentdir'] . '/..';
        $migrateStatus = '';
        $status = $this->taskSsh($worker, $auth)
            ->remoteDirectory($currentProjectRoot, true)
            ->exec('php artisan migrate:status', function ($output) use ($migrateStatus) {
                $migrateStatus .= $output;
            })
            ->run()
            ->wasSuccessful();
        return $status && $migrateStatus != 'No migrations found.';
    }

    public function digipolisValidateCode()
    {
        $local = $this->getLocalSettings();
        $directories = [
          $local['project_root'] . '/app',
          $local['project_root'] . '/resources',
        ];

        // Check if directories exist.
        $checks = [];
        foreach ($directories as $dir) {
          if (!file_exists($dir)) {
            continue;
          }

          $checks[] = $dir;
        }
        if (!$checks) {
          $this->say('! No custom directories to run checks on.');
          return;
        }
        $phpcs = $this
            ->taskPhpCs(
                implode(' ', $checks),
                'PSR1,PSR2',
                $phpcsExtensions
            )
            ->ignore([
                'node_modules',
                'Gruntfile.js',
                '*.md',
                '*.min.js',
                '*.css'
            ])
            ->reportType('full');
        $phpmd = $this->taskPhpMd(
            implode(',', $checks),
            'text',
            $phpmdExtensions
        );
        $collection = $this->collectionBuilder();
        // Add the PHPCS task to the rollback as well so we always have the full
        // report.
        $collection->rollback($phpcs);
        $collection->addTask($phpmd);
        $collection->addTask($phpcs);
        return $collection;
    }

    /**
     * {@inheritdoc}
     */
    protected function preRestoreBackupTask(
        $worker,
        AbstractAuth $auth,
        $remote,
        $opts = ['files' => false, 'data' => false]
    ) {
        if (!$opts['files'] && !$opts['data']) {
            $opts['files'] = true;
            $opts['data'] = true;
        }
        $currentProjectRoot = $remote['currentdir'] . '/..';
        $collection = $this->collectionBuilder();
        $parent = parent::preRestoreBackupTask($worker, $auth, $remote, $opts);
        if ($parent) {
            $collection->addTask($parent);
        }

        if ($opts['data']) {
            $collection
                ->taskSsh($worker, $auth)
                    ->remoteDirectory($currentProjectRoot, true)
                    ->timeout(60)
                    ->exec('php artisan migrate:reset --force');
        }
        return $collection;
    }

    /**
     * {@inheritdoc}
     */
    protected function preSymlinkTask($worker, AbstractAuth $auth, $remote) {
        $collection = $this->collectionBuilder();
        $parent = parent::preSymlinkTask($worker, $auth, $remote);
        if ($parent) {
            $collection->addTask($parent);
        }
        return $collection;
    }

    /**
     * {@inheritdoc}
     */
    protected function postSymlinkTask($worker, AbstractAuth $auth, $remote) {
        $currentProjectRoot = $remote['currentdir'] . '/..';
        $collection = $this->collectionBuilder();
        $parent = parent::postSymlinkTask($worker, $auth, $remote);
        if ($parent) {
            $collection->addTask($parent);
        }
        $collection->taskSsh($worker, $auth)
            ->remoteDirectory($currentProjectRoot, true)
            ->exec('chmod a+x artisan');
        $collection->taskSsh($worker, $auth)
            ->remoteDirectory($currentProjectRoot, true)
            ->exec('rm -rf public/storage')
            ->exec('php artisan storage:link');
        return $collection;
    }

    /**
     * {@inheritdoc}
     */
    protected function clearCacheTask($worker, $auth, $remote)
    {
        $currentProjectRoot = $remote['currentdir'] . '/..';
        return $this->taskSsh($worker, $auth)
                ->remoteDirectory($currentProjectRoot, true)
                ->timeout(120)
                ->exec('php artisan cache:clear');
    }

    /**
     * {@inheritdoc}
     */
    protected function buildTask($archivename = null)
    {
        $archive = is_null($archivename) ? $this->time . '.tar.gz' : $archivename;
        $collection = $this->collectionBuilder();
        $collection
            ->taskThemeCompile()
            ->taskThemeClean()
            ->taskPackageProject($archive)
                ->ignoreFileNames([
                    '.env.example',
                    '.gitattributes',
                    '.gitignore',
                    'README',
                    'README.txt',
                    'README.md',
                    'LICENSE',
                    'LICENSE.txt',
                    'LICENSE.md',
                ]);
        return $collection;
    }

    /**
     * {@inheritdoc}
     */
    protected function defaultDbConfig()
    {
        $rootDir = $this->getConfig()->get('digipolis.root.project', false);
        if (!$rootDir) {
            return false;
        }

        $finder = new Finder();
        $finder->in($rootDir)->ignoreDotFiles(false)->files()->name('.env');
        foreach ($finder as $settingsFile) {
            $env = method_exists(Dotenv::class, 'create')
                ? Dotenv::create(dirname($settingsFile->getRealPath()), $settingsFile->getFilename())
                : new Dotenv(dirname($settingsFile->getRealPath()), $settingsFile->getFilename());
            $env->load();
            break;
        }
        return [
          'default' => [
                'type' => $this->env('DB_CONNECTION', 'mysql'),
                'host' => $this->env('DB_HOST', '127.0.0.1'),
                'port' => $this->env('DB_PORT', '3306'),
                'user' => $this->env('DB_USERNAME', 'forge'),
                'pass' => $this->env('DB_PASSWORD', 'forge'),
                'database' => $this->env('DB_DATABASE'),
                'structureTables' => [],
            ]
        ];
    }

    /**
     * Gets the value of an environment variable.
     *
     * @param  string  $key
     * @param  mixed   $default
     * @return mixed
     */
    protected function env($key, $default = null)
    {
        $value = getenv($key);
        if ($value === false) {
            return is_callable($default) ? call_user_func($default) : $default;
        }
        switch (strtolower($value)) {
            case 'true':
            case '(true)':
                return true;
            case 'false':
            case '(false)':
                return false;
            case 'empty':
            case '(empty)':
                return '';
            case 'null':
            case '(null)':
                return;
        }
        if (strlen($value) > 1 && substr($value, 0, 1) === '"' && substr($value, -1) === '"') {
            return substr($value, 1, -1);
        }
        return $value;
    }
}

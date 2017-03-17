<?php

namespace DigipolisGent\Robo\Laravel;

use DigipolisGent\Robo\Helpers\AbstractRoboFile;
use DigipolisGent\Robo\Task\Deploy\Ssh\Auth\AbstractAuth;
use DigipolisGent\Robo\Task\Deploy\Ssh\Auth\KeyFile;
use Dotenv\Dotenv;
use Symfony\Component\Finder\Finder;

class RoboFileBase extends AbstractRoboFile
{
    /**
     * File backup subdirs.
     *
     * @var type
     */
    protected $fileBackupSubDirs = ['app', 'framework', 'logs'];

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

    protected function preSymlinkTask($worker, AbstractAuth $auth, $remote) {
      $currentProjectRoot = $remote['currentdir'] . '/..';
        $collection = $this->collectionBuilder();
        $parent = parent::preSymlinkTask($worker, $auth, $remote);
        if ($parent) {
            $collection->addTask($parent);
        }
        $collection->taskSsh($worker, $auth)
            ->remoteDirectory($currentProjectRoot, true)
            ->exec('chmod a+x artisan');
        return $collection;
    }

    protected function installTask($worker, AbstractAuth $auth, $remote, $extra = [], $force = false)
    {
        $currentProjectRoot = $remote['currentdir'] . '/..';
        $collection = $this->collectionBuilder();
        $collection->taskSsh($worker, $auth)
            ->remoteDirectory($currentProjectRoot, true)
            // Install can take a long time. Let's set it to 15 minutes.
            ->timeout(900);
        if ($force) {
            $collection->exec('php artisan migrate:reset');
        }
        $collection->exec('vendor/bin/robo digipolis:install-laravel');
        return $collection;
    }

    protected function updateTask($server, AbstractAuth $auth, $remote, $extra = [])
    {
        $currentProjectRoot = $remote['currentdir'] . '/..';
        return $this->taskSsh($server, $auth)
            ->remoteDirectory($currentProjectRoot, true)
            // Updates can take a long time. Let's set it to 15 minutes.
            ->timeout(900)
            ->exec('vendor/bin/robo digipolis:update-laravel');
    }

    protected function clearCacheTask($worker, $auth, $remote)
    {
        $currentProjectRoot = $remote['currentdir'] . '/..';
        return $this->taskSsh($worker, $auth)
                ->remoteDirectory($currentProjectRoot, true)
                ->timeout(120)
                ->exec('php artisan cache:clear');
    }

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
     * Build a Laravel site and push it to the servers.
     *
     * @param array $arguments
     *   Variable amount of arguments. The last argument is the path to the
     *   the private key file (ssh), the penultimate is the ssh user. All
     *   arguments before that are server IP's to deploy to.
     * @param array $opts
     *   The options for this command.
     *
     * @option app The name of the app we're deploying. Used to determine the
     *   directory to deploy to.
     * @option worker The IP of the worker server. Defaults to the first server
     *   given in the arguments.
     *
     * @usage --app=myapp 10.25.2.178 sshuser /home/myuser/.ssh/id_rsa
     */
    public function digipolisDeployLaravel(
        array $arguments,
        $opts = [
            'app' => 'default',
            'worker' => null,
        ]
    ) {
        return $this->deployTask($arguments, $opts);
    }

    /**
     * Build a Laravel site and package it.
     *
     * @param string $archivename
     *   Name of the archive to create.
     *
     * @usage test.tar.gz
     */
    public function digipolisBuildLaravel($archivename = null)
    {
        return $this->buildTask($archivename);
    }

    /**
     * Install or update a Laravel remote site.
     *
     * @param string $server
     *   The server to install the site on.
     * @param string $user
     *   The ssh user to use to connect to the server.
     * @param string $privateKeyFile
     *   The path to the private key file to use to connect to the server.
     * @param array $opts
     *    The options for this command.
     *
     * @option app The name of the app we're deploying. Used to determine the
     *   directory in which the drupal site can be found.
     *
     * @usage --app=myapp 10.25.2.178 sshuser /home/myuser/.ssh/id_rsa
     */
    public function digipolisInitLaravelRemote(
        $server,
        $user,
        $privateKeyFile,
        $opts = [
            'app' => 'default',
            'force-install' => false
        ]
    ) {
        $remote = $this->getRemoteSettings($server, $user, $privateKeyFile, $opts['app']);
        $auth = new KeyFile($user, $privateKeyFile);
        return $this->initRemoteTask($privateKeyFile, $auth, $remote, $opts, $opts['force-install']);
    }

    /**
     * Executes database updates of the Laravel site in the current folder.
     *
     * Executes database updates of the Laravel site in the current folder. Sets
     * the site in maintenance mode before the update and takes in out of
     * maintenance mode after.
     */
    public function digipolisUpdateLaravel()
    {
        $this->readProperties();
        $collection = $this->collectionBuilder();
        $collection
            ->taskExecStack()
                ->exec('php artisan down')
                ->exec('php artisan migrate --force')
                ->exec('php artisan up');
        return $collection;
    }

    /**
     * Install the Laravel site in the current folder.
     */
    public function digipolisInstallLaravel()
    {
        $this->readProperties();
        $collection = $this->collectionBuilder();
        $collection
            ->taskExecStack()
                ->exec('php artisan down')
                ->exec('php artisan migrate --force')
                ->exec('php artisan db:seed --force')
                ->exec('php artisan up');
        return $collection;
    }

    /**
     * Sync the database and files between two Laravel sites.
     *
     * @param string $sourceUser
     *   SSH user to connect to the source server.
     * @param string $sourceHost
     *   IP address of the source server.
     * @param string $sourceKeyFile
     *   Private key file to use to connect to the source server.
     * @param string $destinationUser
     *   SSH user to connect to the destination server.
     * @param string $destinationHost
     *   IP address of the destination server.
     * @param string $destinationKeyFile
     *   Private key file to use to connect to the destination server.
     * @param string $sourceApp
     *   The name of the source app we're syncing. Used to determine the
     *   directory to sync.
     * @param string $destinationApp
     *   The name of the destination app we're syncing. Used to determine the
     *   directory to sync to.
     */
    public function digipolisSyncLaravel(
        $sourceUser,
        $sourceHost,
        $sourceKeyFile,
        $destinationUser,
        $destinationHost,
        $destinationKeyFile,
        $sourceApp = 'default',
        $destinationApp = 'default',
        $opts = ['files' => false, 'data' => false]
    ) {
        if (!$opts['files'] && !$opts['data']) {
            $opts['files'] = true;
            $opts['data'] = true;
        }
        return $this->syncTask(
            $sourceUser,
            $sourceHost,
            $sourceKeyFile,
            $destinationUser,
            $destinationHost,
            $destinationKeyFile,
            $sourceApp,
            $destinationApp,
            $opts
        );
    }

    /**
     * Create a backup of files (storage folder) and database.
     *
     * @param string $host
     *   The server of the website.
     * @param string $user
     *   The ssh user to use to connect to the server.
     * @param string $keyFile
     *   The path to the private key file to use to connect to the server.
     * @param array $opts
     *    The options for this command.
     *
     * @option app The name of the app we're creating the backup for.
     */
    public function digipolisBackupLaravel(
        $host,
        $user,
        $keyFile,
        $opts = ['app' => 'default', 'files' => false, 'data' => false]
    ) {
        if (!$opts['files'] && !$opts['data']) {
            $opts['files'] = true;
            $opts['data'] = true;
        }
        $remote = $this->getRemoteSettings($host, $user, $keyFile, $opts['app']);
        $auth = new KeyFile($user, $keyFile);
        return $this->backupTask($host, $auth, $remote, $opts);
    }

    /**
     * Restore a backup of files (storage folder) and database.
     *
     * @param string $host
     *   The server of the website.
     * @param string $user
     *   The ssh user to use to connect to the server.
     * @param string $keyFile
     *   The path to the private key file to use to connect to the server.
     * @param array $opts
     *    The options for this command.
     *
     * @option app The name of the app we're restoring the backup for.
     * @option timestamp The timestamp when the backup was created. Defaults to
     *   the current time, which is only useful when syncing between servers.
     *
     * @see digipolisBackupLaravel
     */
    public function digipolisRestoreBackupLaravel(
        $host,
        $user,
        $keyFile,
        $opts = [
            'app' => 'default',
            'timestamp' => null,
            'files' => false,
            'data' => false,
        ]
    ) {
        if (!$opts['files'] && !$opts['data']) {
            $opts['files'] = true;
            $opts['data'] = true;
        }
        $remote = $this->getRemoteSettings($host, $user, $keyFile, $opts['app'], $opts['timestamp']);
        $auth = new KeyFile($user, $keyFile);
        return $this->restoreBackupTask($host, $auth, $remote, $opts);
    }

    /**
     * Download a backup of files (storage folder) and database.
     *
     * @param string $host
     *   The server of the website.
     * @param string $user
     *   The ssh user to use to connect to the server.
     * @param string $keyFile
     *   The path to the private key file to use to connect to the server.
     * @param array $opts
     *    The options for this command.
     *
     * @option app The name of the app we're downloading the backup for.
     * @option timestamp The timestamp when the backup was created. Defaults to
     *   the current time, which is only useful when syncing between servers.
     *
     * @see digipolisBackupLaravel
     */
    public function digipolisDownloadBackupLaravel(
        $host,
        $user,
        $keyFile,
        $opts = [
            'app' => 'default',
            'timestamp' => null,
            'files' => false,
            'data' => false,
        ]
    ) {
        if (!$opts['files'] && !$opts['data']) {
            $opts['files'] = true;
            $opts['data'] = true;
        }
        $remote = $this->getRemoteSettings($host, $user, $keyFile, $opts['app'], $opts['timestamp']);
        $auth = new KeyFile($user, $keyFile);
        return $this->downloadBackupTask($host, $auth, $remote, $opts);
    }

    /**
     * Upload a backup of files (storage folder) and database to a server.
     *
     * @param string $host
     *   The server of the website.
     * @param string $user
     *   The ssh user to use to connect to the server.
     * @param string $keyFile
     *   The path to the private key file to use to connect to the server.
     * @param array $opts
     *    The options for this command.
     *
     * @option app The name of the app we're uploading the backup for.
     * @option timestamp The timestamp when the backup was created. Defaults to
     *   the current time, which is only useful when syncing between servers.
     *
     * @see digipolisBackupLaravel
     */
    public function digipolisUploadBackupLaravel(
        $host,
        $user,
        $keyFile,
        $opts = [
            'app' => 'default',
            'timestamp' => null,
            'files' => false,
            'data' => false,
        ]
    ) {
        if (!$opts['files'] && !$opts['data']) {
            $opts['files'] = true;
            $opts['data'] = true;
        }
        $remote = $this->getRemoteSettings($host, $user, $keyFile, $opts['app'], $opts['timestamp']);
        $auth = new KeyFile($user, $keyFile);
        return $this->uploadBackupTask($host, $auth, $remote, $opts);
    }

    protected function defaultDbConfig()
    {
        $rootDir = $this->getConfig()->get('digipolis.root.project', false);
        if (!$rootDir) {
            return false;
        }

        $finder = new Finder();
        $finder->in($rootDir)->ignoreDotFiles(false)->files()->name('.env');
        foreach ($finder as $settingsFile) {
            $env = new Dotenv(dirname($settingsFile->getRealPath()), $settingsFile->getFilename());
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

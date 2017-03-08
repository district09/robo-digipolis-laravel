<?php

namespace DigipolisGent\Robo\Laravel;

use DigipolisGent\Robo\Task\Deploy\Ssh\Auth\KeyFile;
use DigipolisGent\Robo\Task\General\Common\DigipolisPropertiesAwareInterface;
use Robo\Contract\ConfigAwareInterface;
use Symfony\Component\Finder\Finder;

class RoboFileBase extends \Robo\Tasks implements DigipolisPropertiesAwareInterface, ConfigAwareInterface
{
    use \DigipolisGent\Robo\Task\Package\Commands\loadCommands;
    use \DigipolisGent\Robo\Task\General\loadTasks;
    use \DigipolisGent\Robo\Task\General\Common\DigipolisPropertiesAware;
    use \Robo\Common\ConfigAwareTrait;
    use \DigipolisGent\Robo\Task\Deploy\Commands\loadCommands;
    use \DigipolisGent\Robo\Task\Deploy\Traits\SshTrait;
    use \DigipolisGent\Robo\Task\Deploy\Traits\ScpTrait;
    use \Robo\Task\Base\loadTasks;

    /**
     * Stores the request time.
     *
     * @var int
     */
    protected $time;

    /**
     * Create a RoboFileBase instance.
     */
    public function __construct()
    {
        $this->time = time();
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
        $archive = $this->time . '.tar.gz';
        $build = $this->digipolisBuildLaravel($archive);
        $privateKeyFile = array_pop($arguments);
        $user = array_pop($arguments);
        $servers = $arguments;
        $worker = is_null($opts['worker']) ? reset($servers) : $opts['worker'];
        $remote = $this->getRemoteSettings($servers, $user, $privateKeyFile, $opts['app']);
        $releaseDir = $remote['releasesdir'] . '/' . $this->time;
        $auth = new KeyFile($user, $privateKeyFile);
        $currentProjectRoot = $remote['currentdir'] . '/..';

        $collection = $this->collectionBuilder();
        $collection->addTask($build);
        // Create a backup, and a rollback if a Laravel site is present.
        $migrateStatus = '';
        $status = $this->taskSsh($worker, $auth)
            ->remoteDirectory($currentProjectRoot, true)
            ->exec('./artisan migrate:status', function ($output) use ($migrateStatus) {
                $migrateStatus .= $output;
            })
            ->run()
            ->wasSuccessful();
        if ($status && $migrateStatus != 'No migrations found.') {
            $collection->addTask($this->digipolisBackupLaravel($worker, $user, $privateKeyFile, $opts));
            $collection->rollback(
                $this->digipolisRestoreBackupLaravel(
                    $worker,
                    $user,
                    $privateKeyFile,
                    $opts + ['timestamp' => $this->time]
                )
            );
            // Switch the current symlink to the previous release.
            $collection->rollback(
                $this->taskSsh($worker, $auth)
                    ->remoteDirectory($currentProjectRoot, true)
                    ->exec(
                        'vendor/bin/robo digipolis:switch-previous '
                        . $remote['releasesdir']
                        . ' ' . $remote['currentdir']
                    )
            );
        }
        foreach ($servers as $server) {
            $collection
                ->taskPushPackage($server, $auth)
                    ->destinationFolder($releaseDir)
                    ->package($archive)
                ->taskSsh($server, $auth)
                    // Ensure folder structure
                    ->exec('mkdir -p ' . $remote['filesdir'] . '/{app/public,framework/{cache,sessions,views},logs}')
                    ->exec('rm -rf ' . $remote['currentdir'] . '/storage');
            foreach ($remote['symlinks'] as $link) {
                $collection->exec('ln -s -T -f ' . str_replace(':', ' ', $link));
            }
        }
        $collection->addTask($this->digipolisInitLaravelRemote($worker, $user, $privateKeyFile, $opts));
        if (isset($remote['opcache'])) {
            $clearOpcache = 'vendor/bin/robo digipolis:clear-op-cache ' . $remote['opcache']['env'];
            if ( isset($remote['opcache']['host'])) {
                $clearOpcache .= ' --host=' . $remote['opcache']['host'];
            }
            $collection->taskSsh($worker, $auth)
                ->remoteDirectory($currentProjectRoot, true)
                ->exec($clearOpcache);
        }
        foreach ($servers as $server) {
            $collection->completion($this->taskSsh($server, $auth)
                ->remoteDirectory($currentProjectRoot, true)
                ->timeout(30)
                ->exec('vendor/bin/robo digipolis:clean-dir ' . $remote['releasesdir'])
                ->exec('vendor/bin/robo digipolis:clean-dir ' . $remote['backupsdir'])
            );
        }
        // Clear cache.
        $collection->completion($this->taskSsh($worker, $auth)
                ->remoteDirectory($currentProjectRoot, true)
                ->timeout(120)
                ->exec('./artisan cache:clear')
            );
        return $collection;
    }

    /**
     * Switch the current release symlink to the previous release.
     *
     * @param string $releasesDir
     *   Path to the folder containing all releases.
     * @param string $currentSymlink
     *   Path to the current release symlink.
     */
    public function digipolisSwitchPrevious($releasesDir, $currentSymlink)
    {
        $finder = new Finder();
        // Get all releases.
        $releases = iterator_to_array(
            $finder
                ->directories()
                ->in($releasesDir)
                ->sortByName()
                ->depth(0)
                ->getIterator()
        );
        // Last element is the current release.
        array_pop($releases);
        // Normalize the paths.
        $currentDir = realpath($currentSymlink);
        $releasesDir = realpath($releasesDir);
        // Get the right folder within the release dir to symlink.
        $relativeRootDir = substr($currentDir, strlen($releasesDir . '/'));
        $parts = explode('/', $relativeRootDir);
        array_shift($parts);
        $relativeWebDir = implode('/', $parts);
        $previous = end($releases)->getRealPath() . '/' . $relativeWebDir;

        return $this->taskExec('ln -s -T -f ' . $previous . ' ' . $currentSymlink)
            ->run();
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
        ]
    ) {
        $remote = $this->getRemoteSettings($server, $user, $privateKeyFile, $opts['app']);
        $currentProjectRoot = $remote['currentdir'] . '/..';
        $auth = new KeyFile($user, $privateKeyFile);
        $migrateStatus = '';
        $status = $this->taskSsh($server, $auth)
            ->remoteDirectory($currentProjectRoot, true)
            ->exec('./artisan migrate:status', function ($output) use ($migrateStatus) {
                $migrateStatus .= $output;
            })
            ->run()
            ->wasSuccessful();
        $collection = $this->collectionBuilder();
        if (!$status || $migrateStatus != 'No migrations found.') {
            $this->say('Site status failed.');
            $this->say('Triggering install script.');

            $collection->taskSsh($server, $auth)
                ->remoteDirectory($currentProjectRoot, true)
                // Install can take a long time. Let's set it to 15 minutes.
                ->timeout(900)
                ->exec('vendor/bin/robo digipolis:install-laravel');
            return $collection;
        }
        $collection
            ->taskSsh($server, $auth)
                ->remoteDirectory($currentProjectRoot, true)
                // Updates can take a long time. Let's set it to 15 minutes.
                ->timeout(900)
                ->exec('vendor/bin/robo digipolis:update-laravel');
        return $collection;
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
                ->exec('./artisan down')
                ->exec('./artisan migrate --force')
                ->exec('./artisan up');
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
                ->exec('./artisan down')
                ->exec('./artisan migrate --force')
                ->exec('./artisan db:seed --force')
                ->exec('./artisan up');
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
        $destinationApp = 'default'
    ) {
        $collection = $this->collectionBuilder();
        // Create a backup.
        $collection->addTask(
            $this->digipolisBackupLaravel(
                $sourceHost,
                $sourceUser,
                $sourceKeyFile,
                ['app' => $sourceApp]
            )
        );
        // Download the backup.
        $collection->addTask(
            $this->digipolisDownloadBackupLaravel(
                $sourceHost,
                $sourceUser,
                $sourceKeyFile,
                ['app' => $sourceApp, 'timestamp' => null]
            )
        );
        // Upload the backup.
        $collection->addTask(
            $this->digipolisUploadBackupLaravel(
                $destinationHost,
                $destinationUser,
                $destinationKeyFile,
                ['app' => $destinationApp, 'timestamp' => null]
            )
        );
        // Restore the backup.
        $collection->addTask(
            $this->digipolisRestoreBackupLaravel(
                $destinationHost,
                $destinationUser,
                $destinationKeyFile,
                ['app' => $destinationApp, 'timestamp' => null]
            )
        );
        return $collection;
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
    public function digipolisBackupLaravel($host, $user, $keyFile, $opts = ['app' => 'default'])
    {
        $remote = $this->getRemoteSettings($host, $user, $keyFile, $opts['app']);

        $backupDir = $remote['backupsdir'] . '/' . $this->time;
        $currentProjectRoot = $remote['currentdir'] . '/..';
        $auth = new KeyFile($user, $keyFile);

        $dbBackupFile = $this->backupFileName('.sql');
        $dbBackup = 'vendor/bin/robo digipolis:database-backup '
            . '--destination=' . $backupDir . '/' . $dbBackupFile;

        $filesBackupFile = $this->backupFileName('.tar.gz');
        $filesBackup = 'tar -pczhf ' . $backupDir . '/'  . $filesBackupFile
            . ' -C ' . $remote['filesdir'] . ' app framework logs';

        $collection = $this->collectionBuilder();
        $collection
            ->taskSsh($host, $auth)
                ->remoteDirectory($currentProjectRoot, true)
                ->exec('mkdir -p ' . $backupDir)
                ->exec($dbBackup)
                ->exec($filesBackup);
        return $collection;
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
        ]
    ) {
        $remote = $this->getRemoteSettings($host, $user, $keyFile, $opts['app'], $opts['timestamp']);

        $currentProjectRoot = $remote['currentdir'] . '/..';
        $backupDir = $remote['backupsdir'] . '/' . $this->time;
        $auth = new KeyFile($user, $keyFile);

        $filesBackupFile =  $this->backupFileName('.tar.gz', $opts['timestamp']);
        $dbBackupFile =  $this->backupFileName('.sql.gz', $opts['timestamp']);

        $dbRestore = 'vendor/bin/robo digipolis:database-restore '
              . '--source=' . $backupDir . '/' . $dbBackupFile;
        $collection = $this->collectionBuilder();

        // Restore the files backup.
        $collection
            ->taskSsh($host, $auth)
                ->remoteDirectory($remote['filesdir'], true)
                ->exec('rm -rf app/* framework/* logs/* app/.??* framework/.?? logs/.??')
                ->exec('tar -xkzf ' . $backupDir . '/' . $filesBackupFile);

        // Restore the db backup.
        $collection
            ->taskSsh($host, $auth)
                ->remoteDirectory($currentProjectRoot, true)
                ->timeout(60)
                ->exec('./artisan migrate:reset')
                ->exec($dbRestore);
        return $collection;
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
        ]
    ) {
        $remote = $this->getRemoteSettings($host, $user, $keyFile, $opts['app'], $opts['timestamp']);
        $auth = new KeyFile($user, $keyFile);

        $backupDir = $remote['backupsdir'] . '/' . (is_null($opts['timestamp']) ? $this->time : $opts['timestamp']);
        $dbBackupFile = $this->backupFileName('.sql.gz', $opts['timestamp']);
        $filesBackupFile = $this->backupFileName('.tar.gz', $opts['timestamp']);

        $collection = $this->collectionBuilder();
        $collection
            ->taskScp($host, $auth)
                ->get($backupDir . '/' . $dbBackupFile, $dbBackupFile)
                ->get($backupDir . '/' . $filesBackupFile, $filesBackupFile);
        return $collection;
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
        ]
    ) {
        $remote = $this->getRemoteSettings($host, $user, $keyFile, $opts['app'], $opts['timestamp']);
        $auth = new KeyFile($user, $keyFile);

        $backupDir = $remote['backupsdir'] . '/' . (is_null($opts['timestamp']) ? $this->time : $opts['timestamp']);
        $dbBackupFile = $this->backupFileName('.sql.gz', $opts['timestamp']);
        $filesBackupFile = $this->backupFileName('.tar.gz', $opts['timestamp']);

        $collection = $this->collectionBuilder();
        $collection
            ->taskSsh($host, $auth)
                ->exec('mkdir -p ' . $backupDir)
            ->taskScp($host, $auth)
                ->put($backupDir . '/' . $dbBackupFile, $dbBackupFile)
                ->put($backupDir . '/' . $filesBackupFile, $filesBackupFile);
        return $collection;
    }

    /**
     * Helper functions to replace tokens in an array.
     *
     * @param string|array $input
     *   The array or string containing the tokens to replace.
     * @param array $replacements
     *   The token replacements.
     *
     * @return string|array
     *   The input with the tokens replaced with their values.
     */
    protected function tokenReplace($input, $replacements)
    {
        if (is_string($input)) {
            return strtr($input, $replacements);
        }
        if (is_scalar($input) || empty($input)) {
            return $input;
        }
        foreach ($input as &$i) {
            $i = $this->tokenReplace($i, $replacements);
        }
        return $input;
    }

    /**
     * Generate a backup filename based on the given time.
     *
     * @param string $extension
     *   The extension to append to the filename. Must include leading dot.
     * @param int|null $timestamp
     *   The timestamp to generate the backup name from. Defaults to the request
     *   time.
     *
     * @return string
     *   The generated filename.
     */
    protected function backupFileName($extension, $timestamp = null)
    {
        if (is_null($timestamp)) {
            $timestamp = $this->time;
        }
        return $timestamp . '_' . date('Y_m_d_H_i_s', $timestamp) . $extension;
    }

    /**
     * Get the settings from the 'remote' config key, with the tokens replaced.
     *
     * @param string $host
     *   The IP address of the server to get the settings for.
     * @param string $user
     *   The SSH user used to connect to the server.
     * @param string $keyFile
     *   The path to the private key file used to connect to the server.
     * @param string $app
     *   The name of the app these settings apply to.
     * @param string|null $timestamp
     *   The timestamp to use. Defaults to the request time.
     *
     * @return array
     *   The settings for this server and app.
     */
    protected function getRemoteSettings($host, $user, $keyFile, $app, $timestamp = null)
    {
        $this->readProperties();

        // Set up destination config.
        $replacements = array(
            '[user]' => $user,
            '[private-key]' => $keyFile,
            '[app]' => $app,
            '[time]' => is_null($timestamp) ? $this->time : $timestamp,
        );
        if (is_string($host)) {
            $replacements['[server]'] = $host;
        }
        if (is_array($host)) {
            foreach ($host as $key => $server) {
                $replacements['[server-' . $key . ']'] = $server;
            }
        }
        return $this->tokenReplace($this->getConfig()->get('remote'), $replacements);
    }

    protected function defaultDbConfig()
    {
        $rootDir = $this->getConfig()->get('digipolis.root.project', false);
        if (!$rootDir) {
            return false;
        }

        $finder = new \Symfony\Component\Finder\Finder();
        $finder->in($rootDir)->ignoreDotFiles(false)->files()->name('.env');
        foreach ($finder as $settingsFile) {
            $env = new \Dotenv\Dotenv(dirname($settingsFile->getRealPath()), $settingsFile->getFilename());
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

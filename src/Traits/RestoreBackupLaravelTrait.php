<?php

namespace DigipolisGent\Robo\Laravel\Traits;

use DigipolisGent\Robo\Helpers\Traits\AbstractSyncRemoteCommandTrait;
use DigipolisGent\Robo\Task\Deploy\Ssh\Auth\KeyFile;

trait RestoreBackupLaravelTrait
{
    /**
     * @see \DigipolisGent\Robo\Helpers\Traits\TraitDependencyCheckerTrait
     */
    protected function getRestoreBackupLaravelTraitDependencies()
    {
        return [AbstractSyncRemoteCommandTrait::class];
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
}

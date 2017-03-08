# Robo Digipolis Laravel

Used by digipolis, serving as an example.

This package contains a RoboFileBase class that can be used in your own
RoboFile. All commands can be overwritten by overwriting the parent method.

## Example

```php
<?php

use DigipolisGent\Robo\Laravel\RoboFileBase;

class RoboFile extends RoboFileBase
{
    use \Robo\Task\Base\loadTasks;

    /**
     * @inheritdoc
     */
    public function digipolisDeployLaravel(
        array $arguments,
        $opts = [
            'app' => 'default',
            'worker' => null,
        ]
    ) {
        $collection = parent::digipolisDeployLaravel($arguments, $opts);
        $collection->taskExec('/usr/bin/custom-post-release-script.sh');
        return $collection;
    }
}

```

## Available commands

Following the example above, these commands will be available:

```bash
digipolis:backup-laravel           Create a backup of files (storage folder) and database.
digipolis:build-laravel            Build a Laravel site and package it.
digipolis:clean-dir                Partially clean directories.
digipolis:clear-op-cache           Command digipolis:database-backup.
digipolis:database-backup          Command digipolis:database-backup.
digipolis:database-restore         Command digipolis:database-restore.
digipolis:deploy-laravel           Build a Laravel site and push it to the servers.
digipolis:download-backup-laravel  Download a backup of files (storage folder) and database.
digipolis:init-laravel-remote      Install or update a Laravel remote site.
digipolis:install-laravel          Install the Laravel site in the current folder.
digipolis:package-project
digipolis:push-package             Command digipolis:push-package.
digipolis:restore-backup-laravel   Restore a backup of files (storage folder) and database.
digipolis:switch-previous          Switch the current release symlink to the previous release.
digipolis:sync-laravel             Sync the database and files between two Laravel sites.
digipolis:theme-clean
digipolis:theme-compile
digipolis:update-laravel           Executes database updates of the Laravel site in the current folder.
digipolis:upload-backup-laravel    Upload a backup of files (storage folder) and database to a server.
```

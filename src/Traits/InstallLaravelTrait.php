<?php

namespace DigipolisGent\Robo\Laravel\Traits;

use DigipolisGent\CommandBuilder\CommandBuilder;
use DigipolisGent\Robo\Helpers\Traits\AbstractDeployCommandTrait;
use DigipolisGent\Robo\Task\Deploy\Ssh\Auth\AbstractAuth;

trait InstallLaravelTrait
{
    /**
     * @see \DigipolisGent\Robo\Helpers\Traits\TraitDependencyCheckerTrait
     */
    protected function getInstallLaravelTraitDependencies()
    {
        return [AbstractDeployCommandTrait::class];
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
        $collection->taskSsh($worker, $auth)
            ->remoteDirectory($remote['rootdir'], true)
            ->exec('vendor/bin/robo digipolis:install-laravel');
        return $collection;
    }

    /**
     * Install the Laravel site in the current folder.
     */
    public function digipolisInstallLaravel()
    {
        $this->readProperties();
        $collection = $this->collectionBuilder();
        $command = CommandBuilder::create('cd')
            ->addArgument($this->getConfig()->get('digipolis.root.web') . '/..')
            ->addFlag('P')
            ->onSuccess('echo')
            ->addArgument("print_r(DB::select('SHOW TABLES'));")
            ->pipeOutputTo(
                CommandBuilder::create('php')
                    ->addArgument('artisan')
                    ->addArgument('tinker')
            )
            ->pipeOutputTo(
                CommandBuilder::create('grep')
                    ->addArgument('users')
            )->onFailure(
                CommandBuilder::create('php artisan down')
                    ->onSuccess('php artisan migrate --force')
                    ->onSuccess('php artisan db:seed --force')
                    ->onSuccess('php artisan up')
            );
        $collection->taskExec($command);
        return $collection;
    }
}

<?php

namespace DigipolisGent\Robo\Laravel\Traits;

use DigipolisGent\Robo\Helpers\Traits\AbstractDeployCommandTrait;
use DigipolisGent\Robo\Task\Deploy\Ssh\Auth\AbstractAuth;

trait UpdateLaravelTrait
{
    /**
     * @see \DigipolisGent\Robo\Helpers\Traits\TraitDependencyCheckerTrait
     */
    protected function getUpdateLaravelTraitDependencies()
    {
        return [AbstractDeployCommandTrait::class];
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
                ->exec('cd -P ' . $this->getConfig()->get('digipolis.root.web') . '/..')
                ->exec('php artisan down')
                ->exec('php artisan migrate --force')
                ->exec('php artisan up');
        return $collection;
    }

    protected function updateTask($server, AbstractAuth $auth, $remote, $extra = [])
    {
        return $this->taskSsh($server, $auth)
            ->remoteDirectory($remote['rootdir'], true)
            // Updates can take a long time. Let's set it to 15 minutes.
            ->timeout(900)
            ->exec('vendor/bin/robo digipolis:update-laravel');
    }
}

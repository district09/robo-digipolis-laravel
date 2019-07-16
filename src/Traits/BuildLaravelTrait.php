<?php

namespace DigipolisGent\Robo\Laravel\Traits;

use DigipolisGent\Robo\Helpers\Traits\AbstractDeployCommandTrait;

trait BuildLaravelTrait
{
    /**
     * @see \DigipolisGent\Robo\Helpers\Traits\TraitDependencyCheckerTrait
     */
    protected function getBuildLaravelTraitDependencies()
    {
        return [AbstractDeployCommandTrait::class];
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

}

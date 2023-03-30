<?php

namespace DigipolisGent\Robo\Laravel\Robo\Plugin\Commands;

use Consolidation\AnnotatedCommand\Events\CustomEventAwareInterface;
use Robo\Contract\ConfigAwareInterface;
use Robo\Tasks;

class DigipolisLaravelUpdateCommand extends Tasks implements CustomEventAwareInterface, ConfigAwareInterface
{

    use \DigipolisGent\Robo\Helpers\Traits\EventDispatcher;
    use \Consolidation\AnnotatedCommand\Events\CustomEventAwareTrait;
    use \Consolidation\Config\ConfigAwareTrait;
    use \DigipolisGent\Robo\Task\General\Common\DigipolisPropertiesAware;
    use \DigipolisGent\Robo\Task\General\Tasks;

    /**
     * Executes database updates of the Laravel site in the current folder.
     *
     * Executes database updates of the Laravel site in the current folder. Sets
     * the site in maintenance mode before the update and takes in out of
     * maintenance mode after.
     *
     * @command digipolis:update-laravel
     */
    public function digipolisUpdateLaravel() {
        $this->readProperties();
        return $this->handleTaskEvent(
            'digipolis:update-laravel',
            [
                'webroot' => $this->getConfig()->get('digipolis.root.web') . '/..'
            ]
        );
    }
}

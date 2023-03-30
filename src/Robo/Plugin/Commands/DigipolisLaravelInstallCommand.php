<?php

namespace DigipolisGent\Robo\Laravel\Robo\Plugin\Commands;

use Consolidation\AnnotatedCommand\Events\CustomEventAwareInterface;
use Robo\Contract\ConfigAwareInterface;
use Robo\Tasks;

class DigipolisLaravelInstallCommand extends Tasks implements CustomEventAwareInterface, ConfigAwareInterface
{

    use \DigipolisGent\Robo\Helpers\Traits\EventDispatcher;
    use \Consolidation\AnnotatedCommand\Events\CustomEventAwareTrait;
    use \Consolidation\Config\ConfigAwareTrait;
    use \DigipolisGent\Robo\Task\General\Common\DigipolisPropertiesAware;
    use \DigipolisGent\Robo\Task\General\Tasks;

    /**
     * Install the Laravel site in the current folder.
     *
     * @command digipolis:install-laravel
     */
    public function digipolisInstallLaravel() {
        $this->readProperties();
        return $this->handleTaskEvent(
            'digipolis:install-laravel',
            [
                'webroot' => $this->getConfig()->get('digipolis.root.web') . '/..'
            ]
        );
    }
}

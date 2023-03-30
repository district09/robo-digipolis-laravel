<?php

namespace DigipolisGent\Robo\Laravel\EventHandler;

use DigipolisGent\Robo\Helpers\EventHandler\AbstractTaskEventHandler;
use Symfony\Component\EventDispatcher\GenericEvent;

class UpdateLaravelHandler extends AbstractTaskEventHandler
{
    use \Robo\Task\Base\Tasks;

    /**
     * {@inheritDoc}
     */
    public function handle(GenericEvent $event)
    {
        $webroot = $event->getArgument('webroot');
        $collection = $this->collectionBuilder();
        $collection
            ->taskExecStack()
                ->exec('cd -P ' . $webroot)
                ->exec('php artisan down')
                ->exec('php artisan migrate --force')
                ->exec('php artisan up');
        return $collection;
    }

}

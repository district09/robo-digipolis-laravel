<?php

namespace DigipolisGent\Robo\Laravel\EventHandler;

use DigipolisGent\CommandBuilder\CommandBuilder;
use DigipolisGent\Robo\Helpers\EventHandler\AbstractTaskEventHandler;
use Symfony\Component\EventDispatcher\GenericEvent;

class InstallLaravelHandler extends AbstractTaskEventHandler
{
    use \Robo\Task\Base\Tasks;

    /**
     * {@inheritDoc}
     */
    public function handle(GenericEvent $event)
    {
        $webroot = $event->getArgument('webroot');
        $collection = $this->collectionBuilder();
        $command = CommandBuilder::create('cd')
            ->addArgument($webroot)
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
        $collection->taskExec((string) $command);

        return $collection;
    }

}

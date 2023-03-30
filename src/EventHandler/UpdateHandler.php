<?php

namespace DigipolisGent\Robo\Laravel\EventHandler;

use DigipolisGent\Robo\Helpers\EventHandler\AbstractTaskEventHandler;
use DigipolisGent\Robo\Helpers\Util\RemoteConfig;
use DigipolisGent\Robo\Task\Deploy\Ssh\Auth\KeyFile;
use Symfony\Component\EventDispatcher\GenericEvent;

class UpdateHandler extends AbstractTaskEventHandler
{

    use \DigipolisGent\Robo\Task\Deploy\Tasks;

    public function getPriority(): int
    {
        return parent::getPriority() - 100;
    }

    /**
     * {@inheritDoc}
     */
    public function handle(GenericEvent $event)
    {
        /** @var RemoteConfig $remoteConfig */
        $remoteConfig = $event->getArgument('remoteConfig');
        $remoteSettings = $remoteConfig->getRemoteSettings();
        $currentProjectRoot = $remoteSettings['currentdir'] . '/..';
        $collection = $this->collectionBuilder();

        $collection->taskSsh($remoteConfig->getHost(), new KeyFile($remoteConfig->getUser(), $remoteConfig->getPrivateKeyFile()))
            ->remoteDirectory($currentProjectRoot, true)
            // Updates can take a long time. Let's set it to 15 minutes.
            ->timeout(900)
            ->exec('vendor/bin/robo digipolis:update-laravel');

        return $collection;
    }
}

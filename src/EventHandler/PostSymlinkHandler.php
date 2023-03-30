<?php

/*
 * Click nbfs://nbhost/SystemFileSystem/Templates/Licenses/license-default.txt to change this license
 * Click nbfs://nbhost/SystemFileSystem/Templates/Scripting/PHPClass.php to edit this template
 */

namespace DigipolisGent\Robo\Laravel\EventHandler;

use DigipolisGent\CommandBuilder\CommandBuilder;
use DigipolisGent\Robo\Helpers\EventHandler\AbstractTaskEventHandler;
use DigipolisGent\Robo\Helpers\Util\RemoteConfig;
use DigipolisGent\Robo\Task\Deploy\Ssh\Auth\KeyFile;
use Symfony\Component\EventDispatcher\GenericEvent;

/**
 * Description of PostSymlinkHandler
 *
 * @author jelle
 */
class PostSymlinkHandler extends AbstractTaskEventHandler
{

    use \DigipolisGent\Robo\Task\Deploy\Tasks;

    public function getPriority(): int {
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
        $auth = new KeyFile($remoteConfig->getUser(), $remoteConfig->getPrivateKeyFile());

        $collection = $this->collectionBuilder();
        $currentProjectRoot = $remoteSettings['currentdir'] . '/..';
        $collection->taskSsh($remoteConfig->getHost(), $auth)
            ->remoteDirectory($currentProjectRoot, true)
            ->exec('chmod a+x artisan');
        $collection->taskSsh($remoteConfig->getHost(), $auth)
            ->remoteDirectory($currentProjectRoot, true)
            ->exec('rm -rf public/storage')
            ->exec('php artisan storage:link');
        return $collection;
    }
}

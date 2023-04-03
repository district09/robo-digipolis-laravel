<?php

namespace DigipolisGent\Robo\Laravel\EventHandler;

use DigipolisGent\CommandBuilder\CommandBuilder;
use DigipolisGent\Robo\Helpers\EventHandler\AbstractBackupHandler;
use DigipolisGent\Robo\Helpers\Util\RemoteConfig;
use DigipolisGent\Robo\Task\Deploy\Ssh\Auth\KeyFile;
use Symfony\Component\EventDispatcher\GenericEvent;

class PreRestoreBackupRemoteHandler extends AbstractBackupHandler
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
        $options = $event->getArgument('options');
        $auth = new KeyFile($remoteConfig->getUser(), $remoteConfig->getPrivateKeyFile());

        if (!$options['files'] && !$options['data']) {
            $options['files'] = true;
            $options['data'] = true;
        }
        $currentProjectRoot = $remoteSettings['currentdir'] . '/..';
        $collection = $this->collectionBuilder();

        if ($options['data']) {
            $collection
                ->taskSsh($remoteConfig->getHost(), $auth)
                    ->remoteDirectory($currentProjectRoot, true)
                    ->timeout(60)
                    ->exec('php artisan migrate:reset --force');
        }
        return $collection;
    }
}


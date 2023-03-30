<?php

namespace DigipolisGent\Robo\Laravel\EventHandler;

use DigipolisGent\CommandBuilder\CommandBuilder;
use DigipolisGent\Robo\Helpers\EventHandler\AbstractTaskEventHandler;
use DigipolisGent\Robo\Helpers\Util\RemoteConfig;
use DigipolisGent\Robo\Task\Deploy\Ssh\Auth\KeyFile;
use Robo\Contract\ConfigAwareInterface;
use Symfony\Component\EventDispatcher\GenericEvent;

class ClearCacheHandler extends AbstractTaskEventHandler implements ConfigAwareInterface
{

    use \DigipolisGent\Robo\Drupal8\Traits\Drupal8UtilsTrait;
    use \DigipolisGent\Robo\Task\General\Tasks;
    use \DigipolisGent\Robo\Task\Deploy\Tasks;
    use \Consolidation\Config\ConfigAwareTrait;
    use \DigipolisGent\Robo\Task\General\Common\DigipolisPropertiesAware;
    use \Boedah\Robo\Task\Drush\loadTasks;

    /**
     * {@inheritDoc}
     */
    public function handle(GenericEvent $event)
    {
        /** @var RemoteConfig $remoteConfig */
        $remoteConfig = $event->getArgument('remoteConfig');
        $remoteSettings = $remoteConfig->getRemoteSettings();
        $currentWebRoot = $remoteSettings['currentdir'];
        $collection = $this->collectionBuilder();
        $auth = new KeyFile($remoteConfig->getUser(), $remoteConfig->getPrivateKeyFile());

        $currentProjectRoot = $currentWebRoot . '/..';
        $collection->taskSsh($remoteConfig->getHost(), $auth)
                ->remoteDirectory($currentProjectRoot, true)
                ->timeout(120)
                ->exec('php artisan cache:clear');

        return $collection;
    }
}

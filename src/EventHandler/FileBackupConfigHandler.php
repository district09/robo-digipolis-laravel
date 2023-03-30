<?php

namespace DigipolisGent\Robo\Laravel\EventHandler;

use DigipolisGent\Robo\Helpers\EventHandler\AbstractTaskEventHandler;
use Symfony\Component\EventDispatcher\GenericEvent;

class FileBackupConfigHandler extends AbstractTaskEventHandler
{

    /**
     * {@inheritDoc}
     */
    public function handle(GenericEvent $event)
    {
        return [
            'file_backup_subdirs' => ['storage'],
            'exclude_from_backup' => ['storage/logs/*'],
        ];
    }
}

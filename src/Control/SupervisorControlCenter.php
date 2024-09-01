<?php

namespace Anodio\Supervisor\Control;

use Anodio\Supervisor\Configs\SupervisorConfig;
use Anodio\Supervisor\Interfaces\WorkerLockerInterface;
use Anodio\Supervisor\Workers\WorkerManager;
use Swow\Channel;
use Swow\Coroutine;

class SupervisorControlCenter
{
    private Channel $supervisorControlChannel;
    private ?WorkerLockerInterface $workerLocker; // we dont need em, if we run in worker-dev-mode
    private ?WorkerManager $workerManager; // we dont need em, if we run in worker-dev-mode
    private SupervisorConfig $config;

    public function __construct(Channel $supervisorControlChannel, ?WorkerLockerInterface $workerLocker, ?WorkerManager $workerManager, SupervisorConfig $config)
    {
        $this->workerLocker = $workerLocker;
        $this->supervisorControlChannel = $supervisorControlChannel;
        $this->workerManager = $workerManager;
        $this->config = $config;
    }

    public function control(): void
    {
        while (true) {
            try {
                $message = $this->supervisorControlChannel->pop(250000);
            } catch (\Swow\ChannelException $e) {
                if (str_starts_with($e->getMessage(), 'Channel wait producer failed, reason: Timed out for')) {
                    $message = null;
                } else {
                    throw $e;
                }
            }
            if (!is_null($message)) {
                $this->handleMessage($message);
            }
        }
    }

    public function handleMessage(array $message): void
    {
        if ($message['command'] === 'workerStats' && $message['sender'] === 'worker') {
            $memory = $message['stats']['memory']/1024/1024; //mb
            if ($memory > $this->config->maxMemory) {
                $this->workerLocker->lockWorker($message['workerNumber']);
                //worker is locked. After that we will 5 seconds and restart this worker.
                Coroutine::run(function(int $workerNumber) {
                        sleep(5);
                        $this->workerManager->restartWorker($workerNumber);
                        sleep(2);
                        $this->workerLocker->unlockWorker($workerNumber);
                    }, $message['workerNumber']);
            }
        }

    }
}

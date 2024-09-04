<?php

namespace Anodio\Supervisor\WorkerManagement;

use Swow\Channel;
use Swow\Coroutine;
use Symfony\Component\Process\Process;

class WorkerManager
{
    private ?\SplFixedArray $pool = null;

    public function restartWorker(int $workerNumber) {
        $this->pool[$workerNumber]['controlChannel']->push(['command' => 'restart']);
    }

    public function createWorkerPool(int $count, string $workerCommand) {
        if (!is_null($this->pool)) {
            throw new \Exception('Worker pool is already created');
        }
        $this->pool = new \SplFixedArray($count+1);
        $workerNumber = 1; // first worker workerNumber;
        for ($i = 0; $i < $count; $i++) {
            $controlChannel = $this->startWorkerControl($workerNumber+$i, $workerCommand);
            $this->pool[$workerNumber] = [
                'controlChannel' => $controlChannel,
            ];
        }
    }

    public function createWorkerDebugMode(int $workerNumber, string $workerCommand) {
        $process = Process::fromShellCommandline($workerCommand);
        $process->setEnv([
            'DEV_MODE' => 'true',
            'WORKER_NUMBER' => $workerNumber,
            'CONTAINER_NAME' => 'worker'.$workerNumber,
        ]);
        $process->setTimeout(null);
        Coroutine::run(function(Process $process) {
            $process->run(function ($type, $buffer) {
                echo $buffer;
            });
        }, $process);
    }

    public function startWorkerControl(int $workerNumber, string $workerCommand): Channel {
        $controlChannel = new Channel();
        $process = Process::fromShellCommandline($workerCommand);
        $process->setEnv([
            'DEV_MODE' => 'false',
            'WORKER_NUMBER' => $workerNumber,
            'CONTAINER_NAME' => 'worker'.$workerNumber,
        ]);
        $process->setTimeout(null);

        Coroutine::run(function() use ($process, $controlChannel) {
            while (true) {
                if (!$process->isRunning()) {
                    Coroutine::run(function(Process $process) {
                        $process->run(function ($type, $buffer) {
                            echo $buffer;
                        });
                    }, $process);
                }
                try {
                    $message = $controlChannel->pop(100000);
                } catch (\Swow\ChannelException $e) {
                    if (!str_starts_with($e->getMessage(), 'Channel wait producer failed, reason: Timed out for')) {
                        throw $e;
                    }
                }
                if (!is_null($message)) {
                    if ($message['command'] === 'restart') {
                        $process->stop();
                        Coroutine::run(function(Process $process) {
                            $process->run(function ($type, $buffer) {
                                echo $buffer;
                            });
                        }, $process);
                        continue;
                    }
                    if ($message['command'] === 'stop') {
                        $process->stop();
                        break;
                    }
                }
            }
        });

        return $controlChannel;
    }
}

<?php

namespace Anodio\Supervisor\Control;

use Anodio\Core\ContainerStorage;
use Anodio\Core\Helpers\Log;
use Anodio\Supervisor\Configs\SupervisorConfig;
use Anodio\Supervisor\Interfaces\WorkerLockerInterface;
use Anodio\Supervisor\WorkerManagement\WorkerManager;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\InMemory;
use Swow\Channel;
use Swow\Coroutine;

class SupervisorControlCenter
{
    private Channel $supervisorControlChannel;
    private ?WorkerLockerInterface $workerLocker; // we dont need em, if we run in worker-dev-mode
    private ?WorkerManager $workerManager; // we dont need em, if we run in worker-dev-mode
    private SupervisorConfig $config;
    private \Prometheus\Storage\Adapter $metricsStorage;

    public function __construct(Channel $supervisorControlChannel, ?WorkerLockerInterface $workerLocker, ?WorkerManager $workerManager, SupervisorConfig $config, InMemory $inMemoryMetricsStorage)
    {
        $this->workerLocker = $workerLocker;
        $this->supervisorControlChannel = $supervisorControlChannel;
        $this->workerManager = $workerManager;
        $this->config = $config;
        $this->metricsStorage = $inMemoryMetricsStorage;
    }

    public function control(): void
    {
        while (true) {
            try {
                $message = $this->supervisorControlChannel->pop();
            } catch (\Swow\ChannelException $e) {
                if (str_starts_with($e->getMessage(), 'Channel wait producer failed, reason: Timed out for')) {
                    $message = null;
                } else {
                    throw $e;
                }
            }
            if (!is_null($message)) {
                $this->handleMessage($message);
            } else {
                Log::warning('No message from supervisor control channel');
            }
        }
    }

    public function handleMessage(array $message): void
    {
        if ($message['command'] === 'updateBatchMetrics' && ($message['sender'] === 'worker' || $message['sender'] === 'http-proxy')) {
            foreach ($message['data'] as $dataInfo) {
                if ($dataInfo['command'] === 'updateCounterMetrics') {
                    $this->metricsStorage->updateCounter($dataInfo['data']);
                }
                if ($dataInfo['command'] === 'updateGaugeMetrics') {
                    $this->metricsStorage->updateGauge($dataInfo['data']);
                }
                if ($dataInfo['command'] === 'updateHistogramMetrics') {
                    $this->metricsStorage->updateHistogram($dataInfo['data']);
                }
                if ($dataInfo['command'] === 'updateSummaryMetrics') {
                    $this->metricsStorage->updateSummary($dataInfo['data']);
                }
            }
        }
        if ($message['command'] === 'updateCounterMetrics' && ($message['sender'] === 'worker' || $message['sender'] === 'http-proxy')) {
            $this->metricsStorage->updateCounter($message['data']);
        }
        if ($message['command'] === 'updateGaugeMetrics' && ($message['sender'] === 'worker' || $message['sender'] === 'http-proxy')) {
            $this->metricsStorage->updateGauge($message['data']);
        }
        if ($message['command'] === 'updateHistogramMetrics' && ($message['sender'] === 'worker' || $message['sender'] === 'http-proxy')) {
            $this->metricsStorage->updateHistogram($message['data']);
        }
        if ($message['command'] === 'updateSummaryMetrics' && ($message['sender'] === 'worker' || $message['sender'] === 'http-proxy')) {
            $this->metricsStorage->updateSummary($message['data']);
        }

        if ($message['command'] === 'workerStats' && $message['sender'] === 'worker') {
            $memory = $message['stats']['memory']/1024/1024; //mb
            if ($memory > $this->config->maxMemory) {
                $this->workerLocker->lockWorker($message['workerNumber']);
                echo json_encode(['message' => 'Worker '.$message['workerNumber'].' is locked because of memory limit exceeded.']);
                $collectorRegistry = ContainerStorage::getContainer()->get(CollectorRegistry::class);
                //worker is locked. After that we will wait 5 seconds and restart this worker.
                Coroutine::run(function(int $workerNumber, CollectorRegistry $collectorRegistry) {
                        sleep(5);
                        $collectorRegistry->getOrRegisterCounter('system_php', 'supervisor_restarted_worker_by_memory', 'Count of worker restarts because of memory limit exceeded');
                        $this->workerManager->restartWorker($workerNumber);
                        sleep(2);
                        $this->workerLocker->unlockWorker($workerNumber);
                    }, $message['workerNumber'], $collectorRegistry);
            }
        }

    }
}

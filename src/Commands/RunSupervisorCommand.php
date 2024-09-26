<?php

namespace Anodio\Supervisor\Commands;

use Anodio\Core\ContainerStorage;
use Anodio\Core\Helpers\Log;
use Anodio\Supervisor\Configs\SupervisorConfig;
use Anodio\Supervisor\Control\HttpProxyControlClient;
use Anodio\Supervisor\Control\SupervisorControlCenter;
use Anodio\Supervisor\SignalControl\SignalController;
use Anodio\Supervisor\WorkerManagement\WorkerManager;
use DI\Attribute\Inject;
use GuzzleHttp\Exception\ClientException;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Psr\Log\LoggerInterface;
use Swow\Buffer;
use Swow\Channel;
use Swow\Coroutine;
use Swow\Psr7\Message\Response;
use Swow\Psr7\Server\Server;
use Swow\Psr7\Server\ServerConnection;
use Swow\Socket;
use Swow\SocketException;
use Swow\Stream\EofStream;
use Swow\Stream\JsonStream;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\Process;

#[\Anodio\Core\Attributes\Command('supervisor:run', description: 'Run supervisor')]
class RunSupervisorCommand extends Command
{
    #[Inject]
    public SupervisorConfig $supervisorConfig;

    protected function configure(): void
    {
        $this->setDescription('Run supervisor');
    }

    protected function execute(\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output): int
    {
        $this->runSupervisor();
        return 0;
    }

    private array $allowedModes = [
        'http'=>'http',
        'ws'=>'ws',
        'tcp'=>'tcp',
        'grpc'=>'grpc',
        'kafka'=>'kafka',
    ];


    protected function runSupervisor(): bool
    {
        if (!isset($this->allowedModes[$this->supervisorConfig->appMode])) {
            throw new \Exception('App mode is not recognized');
        }
        $supervisorControlChannel = $this->runSupervisorControlServer();
        $this->runSupervisorMetricsServer();

        if (!$this->supervisorConfig->devMode) {
            $workerManager = new WorkerManager();
            if ($this->supervisorConfig->appMode=='http') {
                $proxyHttpControlChannel = $this->runHttpProxyServerProcess(); //todo
                $workerCount = $this->supervisorConfig->workerCount;
                $workerManager->createWorkerPool($workerCount, $this->supervisorConfig->workerCommand); //todo
                $workerLocker = HttpProxyControlClient::getInstance();
            } elseif ('true'=='false') {

            } else {
                throw new \Exception('App mode is not recognized');
            }
            $supervisorControlCenter = new SupervisorControlCenter(
                supervisorControlChannel: $supervisorControlChannel,
                workerLocker: $workerLocker,
                workerManager: $workerManager,
                config: $this->supervisorConfig,
                inMemoryMetricsStorage: ContainerStorage::getContainer()->get(\Prometheus\Storage\Adapter::class)
            );
        } else {
            if ($this->supervisorConfig->appMode=='http') {
                $proxyHttpControlChannel = $this->runHttpProxyServerProcess();
            } elseif ('true'=='false') {

            } else {
                throw new \Exception('App mode is not recognized');
            }
            $supervisorControlCenter = new SupervisorControlCenter(
                supervisorControlChannel: $supervisorControlChannel,
                workerLocker: null,
                workerManager: null,
                config: $this->supervisorConfig,
                inMemoryMetricsStorage: ContainerStorage::getContainer()->get(\Prometheus\Storage\Adapter::class)
            );
        }

        $supervisorControlCenter->control();
        return true;
    }

    public function runSupervisorMetricsServer(): void {
        Coroutine::run(function() {
            $registry = ContainerStorage::getMainContainer()->get(CollectorRegistry::class);
            ContainerStorage::setContainer(ContainerStorage::getMainContainer());
            while(true) {
                $registry->getOrRegisterGauge('system_php', 'supervisor_memory_peak_usage_gauge', 'supervisor_memory_peak_usage_gauge')
                    ->set(memory_get_peak_usage(true) / 1024 / 1024);
                $registry->getOrRegisterGauge('system_php', 'supervisor_memory_usage_gauge', 'supervisor_memory_usage_gauge')
                    ->set(memory_get_usage(true) / 1024 / 1024);
                $cpuAvg = sys_getloadavg();
                $registry->getOrRegisterGauge('system_php', 'supervisor_cpu_usage_gauge', 'supervisor_cpu_usage_gauge', ['per'])
                    ->set($cpuAvg[0], ['1min']);
                $registry->getOrRegisterGauge('system_php', 'supervisor_cpu_usage_gauge', 'supervisor_cpu_usage_gauge', ['per'])
                    ->set($cpuAvg[1], ['5min']);
                $registry->getOrRegisterGauge('system_php', 'supervisor_cpu_usage_gauge', 'supervisor_cpu_usage_gauge', ['per'])
                    ->set($cpuAvg[2], ['15min']);
                sleep(5);
            }
        });
        Coroutine::run(
            function() {
                $server = new Server(Socket::TYPE_TCP);
                $server->bind('0.0.0.0', 7071)->listen();
                while(true) {
                    $connection = $server->acceptConnection();
                    $connection->recvHttpRequest();
                    try {
                        $renderer = new RenderTextFormat();
                        $response = new Response();
                        $response->addHeader('Content-Type', RenderTextFormat::MIME_TYPE);
                        $response->getBody()->write(
                            $renderer->render(
                                ContainerStorage::getMainContainer()
                                    ->get(CollectorRegistry::class)
                                    ->getMetricFamilySamples()
                            )
                        );

//                        ContainerStorage::setContainer(ContainerStorage::getMainContainer());
//                        ContainerStorage::getContainer()->get(LoggerInterface::class)
//                            ->info('Metrics requested', ['metrics'=>
//                            $renderer->render(
//                            ContainerStorage::getMainContainer()
//                                ->get(CollectorRegistry::class)
//                                ->getMetricFamilySamples()
//                            )
//                        ]);
//                        ContainerStorage::removeContainer();

                    } catch (ClientException $e) {
                        $response = $e->getResponse();
                    }
                    $connection->sendHttpResponse($response);
                    $connection->close();
                }
            }
        );
    }

    public function runSupervisorControlServer(): Channel {
        $controlChannel = new Channel(1000);
        Coroutine::run(function() use ($controlChannel) {
            $server = new Socket(Socket::TYPE_TCP);
            $server->bind('0.0.0.0', 7079)->listen();
            while (true) {
                $connection = $server->accept();
                Coroutine::run(function(Socket $connection, Channel $controlChannel) {
                    $buffer = new Buffer(64000);
                    try {
                        while (true) {
                            $length = $connection->recv($buffer);
                            if ($length === 0) {
                                break;
                            }
                            $message = $buffer->read(length: $length);

                            $messageExploded = explode('}{', $message);
                            if (count($messageExploded)>1) {
                                $count = count($messageExploded);
                                foreach ($messageExploded as $key=>$oneMessage) {
                                    if ($key==0) {
                                        $oneMessage = $oneMessage.'}';
                                    } elseif ($key==$count-1) {
                                        $oneMessage = '{'.$oneMessage;
                                    } else {
                                        $oneMessage = '{'.$oneMessage.'}';
                                    }
                                    $controlChannel->push(json_decode($oneMessage, true, 512, JSON_THROW_ON_ERROR));
                                }
                            } else {
                                $controlChannel->push(json_decode($message, true, 512, JSON_THROW_ON_ERROR));
                            }
                        }
                    } catch (\Throwable $exception) {
                        echo json_encode('Error in supervisorControl: '.$exception->getMessage());
                        SignalController::getInstance()->sendExitSignal(1);
                    }
                }, $connection, $controlChannel);
            }
        });
        return $controlChannel;
    }

    public function runHttpProxyServerProcess(): Channel {
        $controlChannel = new Channel();
        $process = Process::fromShellCommandline('php '.BASE_PATH.'/app.php supervisor:http-proxy-run');
        $envs = [
            'CONTAINER_NAME'=>'http-proxy',
        ];
        if (trim($this->supervisorConfig->envsForHttpProxy)!=='') {
            $explodedEnvs = explode(';', $this->supervisorConfig->envsForHttpProxy);
            foreach ($explodedEnvs as $env) {
                $explodedEnv = explode('=', $env);
                $envs[$explodedEnv[0]] = $explodedEnv[1];
            }
        }
        $process->setEnv($envs);
        $process->setTimeout(null);
        Coroutine::run(function(Process $process) {
            $process->run(function ($type, $buffer) {
                echo $buffer;
            });
        }, $process);
        Coroutine::run(function() use ($controlChannel, $process) {
            while (true) {
                $message = $controlChannel->pop();
                if ($message['command'] === 'stop') {
                    $process->stop();
                    break;
                }
                if ($message['restart'] === 'restart') {
                    $process->stop();
                    Coroutine::run(function(Process $process) {
                        $process->run(function ($type, $buffer) {
                            echo $buffer;
                        });
                    }, $process);
                }
            }
        });
        return $controlChannel;
    }
}

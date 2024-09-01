<?php

namespace Anodio\Supervisor\Commands;

use Anodio\Supervisor\Configs\SupervisorConfig;
use Anodio\Supervisor\Control\HttpServerClientServer;
use Anodio\Supervisor\Control\SupervisorControlCenter;
use Anodio\Supervisor\Workers\WorkerManager;
use DI\Attribute\Inject;
use Swow\Buffer;
use Swow\Channel;
use Swow\Coroutine;
use Swow\Socket;
use Swow\SocketException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\Process;

#[\Anodio\Core\Attributes\Command('supervisor:run')]
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

        if (!$this->supervisorConfig->devMode) {
            $workerManager = new WorkerManager();
            if ($this->supervisorConfig->appMode=='http') {
                $workerCount = $this->supervisorConfig->workerCount;
                $workerLocker = HttpServerClientServer::getInstance();
                $proxyHttpControlChannel = $this->runHttpProxyServerProcess(); //todo
                $workerManager->createWorkerPool($workerCount, $this->supervisorConfig->workerCommand); //todo
            } elseif ('true'=='false') {

            } else {
                throw new \Exception('App mode is not recognized');
            }
            $supervisorControlCenter = new SupervisorControlCenter($supervisorControlChannel, $workerLocker, $workerManager, $this->supervisorConfig);
        } else {
            if ($this->supervisorConfig->appMode=='http') {
                $proxyHttpControlChannel = $this->runHttpProxyServerProcess();
            } elseif ('true'=='false') {

            } else {
                throw new \Exception('App mode is not recognized');
            }
            $supervisorControlCenter = new SupervisorControlCenter($supervisorControlChannel, null, null, $this->supervisorConfig);
        }

        $supervisorControlCenter->control();
        return true;
    }

    public function runSupervisorControlServer(): Channel {
        $controlChannel = new Channel(1000);
        Coroutine::run(function() use ($controlChannel) {
            $server = new Socket(Socket::TYPE_TCP);
            $server->bind('0.0.0.0', 7079)->listen();
            while (true) {
                $connection = $server->accept();
                Coroutine::run(function(Socket $connection, Channel $controlChannel) {
                    $buffer = new Buffer(Buffer::COMMON_SIZE);
                    try {
                        while (true) {
                            $length = $connection->recv($buffer);
                            if ($length === 0) {
                                break;
                            }
                            $message = $buffer->read(length: $length);
                            $controlChannel->push(json_decode($message, true));
                        }
                    } catch (SocketException $exception) {
                        throw $exception;
                    }
                }, $connection, $controlChannel);
            }
        });
        return $controlChannel;
    }

    public function runHttpProxyServerProcess(): Channel {
        $controlChannel = new Channel();
        $process = Process::fromShellCommandline('php '.BASE_PATH.'/app.php supervisor:http-proxy-run');
        if (trim($this->supervisorConfig->envsForHttpProxy)!=='') {
            $explodedEnvs = explode(';', $this->supervisorConfig->envsForHttpProxy);
            $envs = [];
            foreach ($explodedEnvs as $env) {
                $explodedEnv = explode('=', $env);
                $envs[$explodedEnv[0]] = $explodedEnv[1];
            }
            $process->setEnv($envs);
        }
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

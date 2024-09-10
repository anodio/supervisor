<?php

namespace Anodio\Supervisor\Servers;

use Anodio\Core\ContainerStorage;
use Anodio\Supervisor\Configs\SupervisorConfig;
use Anodio\Supervisor\SignalControl\SignalController;
use Anodio\Supervisor\WorkerManagement\WorkerManager;
use DI\Attribute\Inject;
use Prometheus\CollectorRegistry;
use Swow\Buffer;
use Swow\Channel;
use Swow\Coroutine;
use Swow\Psr7\Server\Server;
use Swow\Psr7\Server\ServerConnection;
use Swow\Socket;
use Swow\SocketException;

class HttpProxyServer
{
    private ?\SplFixedArray $pool = null;

    #[Inject]
    public SupervisorConfig $supervisorConfig;

    private int $lastCalledWorker = 0;

    protected function createServer(): Server
    {
        $host = '0.0.0.0';
        $port = 8080;
        $bindFlag = Socket::BIND_FLAG_NONE;

        $server = new Server(Socket::TYPE_TCP);
        $server->bind($host, $port, $bindFlag)->listen();
        echo json_encode(['msg' => 'Http server starting at ' . $host . ':' . $port]) . PHP_EOL;
        return $server;
    }

    private function createControlTCPServer(): Channel
    {
        $controlChannel = new Channel();
        Coroutine::run(function () use ($controlChannel) {
            $server = new Socket(Socket::TYPE_TCP);
            $server->bind('0.0.0.0', 7080)->listen();
            while (true) {
                $connection = $server->accept();
                echo "No.{$connection->getFd()} established" . PHP_EOL;
                $buffer = new Buffer(Buffer::COMMON_SIZE);
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
                        $controlChannel->push(json_decode($message, true));
                    }
                    echo "No.{$connection->getFd()} closed" . PHP_EOL;
                } catch (SocketException $exception) {
                    echo "No.{$connection->getFd()} goaway! {$exception->getMessage()}" . PHP_EOL;
                }
            }
        });
        return $controlChannel;
    }

    private function runControl()
    {
        Coroutine::run(function() {
           $registry = ContainerStorage::getMainContainer()->get(CollectorRegistry::class);
           ContainerStorage::setContainer(ContainerStorage::getMainContainer());
           while(true) {
               $registry->getOrRegisterGauge('system_php', 'http_proxy_memory_usage_gauge', 'http_proxy_memory_usage_gauge')
                   ->set(memory_get_usage() / 1024 / 1024);
               $cpuAvg = sys_getloadavg();
               $registry->getOrRegisterGauge('system_php', 'http_proxy_cpu_usage_gauge', 'http_proxy_cpu_usage_gauge', ['per'])
                   ->set($cpuAvg[0], ['1min']);
                $registry->getOrRegisterGauge('system_php', 'http_proxy_cpu_usage_gauge', 'http_proxy_cpu_usage_gauge', ['per'])
                    ->set($cpuAvg[1], ['5min']);
                $registry->getOrRegisterGauge('system_php', 'http_proxy_cpu_usage_gauge', 'http_proxy_cpu_usage_gauge', ['per'])
                    ->set($cpuAvg[2], ['15min']);
               sleep(5);
           }
        });
        Coroutine::run(function () {
            $channel = $this->createControlTCPServer();
            while (true) {
                $message = $channel->pop();
                if ($message['command'] === 'lockWorker') {
                    $this->pool[$message['workerNumber']]['locked'] = true;
                    continue;
                }
                if ($message['command'] === 'unlockWorker') {
                    $this->pool[$message['workerNumber']]['locked'] = false;
                    continue;
                }
                if ($message['command'] === 'stop') {
                    echo json_encode(['msg' => 'Got Server stop signal. Http server stopping']) . PHP_EOL;
                    SignalController::getInstance()->sendExitSignal(0);
                    break;
                }
            }
        });
    }

    /**
     * This method checks if port is already opened or not yet
     * 40 times with 0.25s sleep between each check
     * if still not opened - throw an exception
     * @param $workerNumber
     * @return bool
     * @throws \Exception
     */
    private function checkIfWorkerIsReady($workerNumber): bool
    {
        $port = $workerNumber + 7080;
        $tries = 0;
        while ($tries < 40) {
            $connection = @fsockopen('0.0.0.0', $port);
            if (is_resource($connection)) {
                fclose($connection);
                return true;
            }
            $tries++;
            usleep(250000);
        }
        throw new \Exception('Alarm, DEV Worker is not ready. Port: ' . $port . ' is not opened');
    }

    public function run(): bool
    {
        $this->pool = new \SplFixedArray($this->supervisorConfig->workerCount);

        if ($this->supervisorConfig->devMode) {
            $startWorkerCount = 0;
        } else {
            $startWorkerCount = $this->supervisorConfig->workerCount;
        }

        $workerManager = new WorkerManager();
        if ($this->supervisorConfig->devMode) {
            echo json_encode(['msg' => 'Http server in dev worker mode']) . PHP_EOL;
        } else {
            echo json_encode(['msg' => 'Http server in static pool worker mode']) . PHP_EOL;
            for ($i = 0; $i < $startWorkerCount; $i++) {
                $this->pool[$i] = [
                    'locked' => false,
                    'port' => 8081 + $i,
                ];
            }
        }
        $this->runControl();
        $server = $this->createServer();
        while (true) {
            try {
                $connection = null;
                $connection = $server->acceptConnection();
                // now lets resend this psr7 request to worker via guzzle
                Coroutine::run(function (ServerConnection $connection) use ($workerManager) {
                    $request = $connection->recvHttpRequest();
                    if ($this->supervisorConfig->devMode) {
                        $workerNumber = $this->createOneTimeWorker($workerManager);
                    } else {
                        $workerNumber = $this->getNextWorkerNumber();
                    }
                    $client = new \GuzzleHttp\Client();
                    $this->checkIfWorkerIsReady($workerNumber);
                    $workerPort = $workerNumber + 8080;
                    //separate requests to queries with bodies and without them
                    $uri = 'http://0.0.0.0:' . $workerPort . $request->getUri();
                    if (!empty($request->getQueryParams())) {
                        $uri .= '?' . $this->convertArrayQueryParamsToString($request->getQueryParams());
                    }
                    try {
                        if ($request->getBody()->getSize() === 0) {
                            $response = $client->request($request->getMethod(), $uri, [
                                'headers' => $request->getHeaders(),
                                'timeout' => ($this->supervisorConfig->devMode) ? 300 : 10,
                            ]);
                        } else {
                            $response = $client->request($request->getMethod(), $uri, [
                                'headers' => $request->getHeaders(),
                                'body' => $request->getBody()->getContents(),
                                'timeout' => ($this->supervisorConfig->devMode) ? 300 : 10,
                            ]);
                        }
                    } catch (\Throwable $e) {
                        if (!method_exists($e, 'getResponse')) {
                            $response = new \GuzzleHttp\Psr7\Response(500, [], json_encode(['msg' => 'Http server error: ' . $e->getMessage()]));
                        } else {
                            $response = $e->getResponse();
                        }
                    }
                    $connection->sendHttpResponse($response);
                    $connection->close();
                }, $connection);
            } catch (\Exception $exception) {
                echo json_encode(['msg' => 'Http server error: ' . $exception->getMessage()]) . PHP_EOL;
            }
        }
        return true;
    }

    /**
     * This function will try to increment lastCalledWorker and return value of 'port' subindex of $this->pool array
     * but! There is also 'locked' subindex, that means that we need to skip this worker now.
     * so we need to check if worker is locked and if it is, we need to skip it and call this function again
     */
    private function getNextWorkerNumber(int $tries = 0)
    {
        if ($tries > 100) {
            //todo send allocation error to supervisor, ask him to kill all workers and servers and restart them.
            throw new \Exception('All workers are locked');
        }
        $this->lastCalledWorker++;
        if ($this->lastCalledWorker >= count($this->pool)) {
            $this->lastCalledWorker = 0;
        }
        if ($this->pool[$this->lastCalledWorker]['locked']) {
            return $this->getNextWorkerNumber();
        }
        return $this->pool[$this->lastCalledWorker]['port']-8080;
    }

    private function convertArrayQueryParamsToString(array $getQueryParams)
    {
        $query = '';
        foreach ($getQueryParams as $key => $value) {
            $query .= $key . '=' . $value . '&';
        }
        return $query;
    }

    private function createOneTimeWorker(WorkerManager $workerManager): int
    {
        if ($this->lastCalledWorker > 99) {
            $this->lastCalledWorker = 0;
        }
        $workerNumber = 1 + $this->lastCalledWorker;
        $this->lastCalledWorker = $this->lastCalledWorker + 1;
        $workerManager->createWorkerDebugMode($workerNumber, $this->supervisorConfig->workerCommand);
        return $workerNumber;
    }
}

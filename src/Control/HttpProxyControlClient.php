<?php

namespace Anodio\Supervisor\Control;

use Anodio\Supervisor\Interfaces\WorkerLockerInterface;

class HttpProxyControlClient implements WorkerLockerInterface
{
    private static $instance;

    /**
     * @return HttpProxyControlClient
     * @deprecated
     */
    public static function getInstance(): HttpProxyControlClient
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
//        $this->connect();
    }

    /**
     * @var null|resource|\Socket
     */
    private $socket = null;

    /**
     * This function should connect to tcp server
     * And save connection to property
     * @return void
     */
    public function connect()
    {
        $host = "127.0.0.1";
        $port = 7080;

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) {
            throw new \RuntimeException("socket_create() failed: reason: " . socket_strerror(socket_last_error()));
        }

        $result = socket_connect($socket, $host, $port);

        if ($result === false) {
            throw new \RuntimeException("socket_connect() failed.\nReason: ($result) " . socket_strerror(socket_last_error($socket)));
        }

        $this->socket = $socket;

    }

    /**
     * This function should send json message to tcp server
     * @param array $message
     * @return void
     */
    private function send(array $message) {
        $json = json_encode($message);
        if (is_null($this->socket)) {
            $this->connect();
        }
        if (is_null($this->socket)) {
            throw new \RuntimeException("Socket is not connected");
        }
        socket_write($this->socket, $json, strlen($json));
    }

    public function lockWorker(int $workerNumber) {
        $this->send(['command' => 'lockWorker', 'workerNumber' => $workerNumber]);
    }
    public function unlockWorker(int $workerNumber) {
        $this->send(['command' => 'lockWorker', 'workerNumber' => $workerNumber]);
    }
}

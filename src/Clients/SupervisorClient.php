<?php

namespace Anodio\Supervisor\Clients;

class SupervisorClient
{
    private static $instance;

    /**
     * need to use through container
     * @return SupervisorClient
     * @deprecated
     */
    public static function getInstance(): SupervisorClient
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->connect();
    }

    private $socket;

    /**
     * This function should connect to tcp server
     * And save connection to property
     * @return void
     */
    public function connect()
    {
        $host = "127.0.0.1";
        $port = 7079;

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
    public function send(array $message, string $sender = 'worker', int $workerNumber = 0) {
        if ($sender === 'worker' && $workerNumber<=0) {
            throw new \Exception('workerNumber is not set');
        }
        if ($sender === 'worker' && $workerNumber>0) {
            $message['sender']=$sender;
            $message['workerNumber'] = $workerNumber;
        }
        if ($sender!=='worker') {
            $message['sender']=$sender;
        }

        $json = json_encode($message);
        socket_write($this->socket, $json, strlen($json));
    }
}

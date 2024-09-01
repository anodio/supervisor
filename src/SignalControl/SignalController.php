<?php

namespace Anodio\Supervisor\SignalControl;

use Swow\Channel;

class SignalController
{
    private Channel $exitChannel;

    private static $instance = null;

    private function __construct()
    {
        $this->exitChannel = new Channel(1);
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function control()
    {
        while (true) {
            try {
                if ($code = $this->exitChannel->pop(3000000) === null) {
                    exit($code);
                }
            } catch (\Throwable $e) {
                echo $e->getMessage();
                echo $e->getTraceAsString();
                exit(1);
            }

        }
    }

    public function sendExitSignal(int $code)
    {
        $this->exitChannel->push($code);
    }

    public function getExitChannel()
    {
        return $this->exitChannel;
    }
}

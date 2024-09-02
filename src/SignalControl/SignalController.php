<?php

namespace Anodio\Supervisor\SignalControl;

use Swow\Channel;

/**
 * For now we use it as singleton, not through container.
 */
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
                if (($code = $this->exitChannel->pop()) !== null) {
                    echo json_encode(['msg' => 'Worker exiting with code: ' . $code]) . PHP_EOL;
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

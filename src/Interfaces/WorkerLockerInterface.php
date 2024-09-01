<?php

namespace Anodio\Supervisor\Interfaces;

interface WorkerLockerInterface
{
    public function lockWorker(int $workerNumber);

    public function unlockWorker(int $workerNumber);
}


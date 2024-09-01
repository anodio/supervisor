<?php

namespace Anodio\Supervisor\Configs;

use Anodio\Core\AttributeInterfaces\AbstractConfig;
use Anodio\Core\Attributes\Config;
use Anodio\Core\Configuration\Env;
use Anodio\Core\Configuration\EnvRequiredNotEmpty;

#[Config('supervisor')]
class SupervisorConfig extends AbstractConfig
{

    #[Env('APP_MODE', 'http')]
    public string $appMode;

    #[EnvRequiredNotEmpty('WORKER_COMMAND', 'php '.BASE_PATH.'/app.php http:run-worker')]
    public string $workerCommand;

    #[Env('HTTP_PROXY_ENVS', '')]
    public string $envsForHttpProxy;

    #[Env('DEV_MODE', false)]
    public bool $devMode = false;

    #[Env('WORKER_COUNT', 1)]
    public int $workerCount;

    #[Env('MAX_MEMORY', 128)]
    public int $maxMemory;
}

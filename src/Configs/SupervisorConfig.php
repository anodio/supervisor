<?php

namespace Anodio\Supervisor\Configs;

use Anodio\Core\AttributeInterfaces\AbstractConfig;
use Anodio\Core\Attributes\Config;
use Anodio\Core\Configuration\Env;

#[Config('supervisor')]
class SupervisorConfig extends AbstractConfig
{

    #[Env('SUPERVISOR_APP_MODE', 'http')]
    public string $appMode;

    #[Env('SUPERVISOR_WORKER_COMMAND', 'php /var/www/php/app.php http:run-worker')]
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

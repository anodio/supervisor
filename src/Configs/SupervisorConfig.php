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

    #[Env('WORKER_MAX_MEMORY', 100)]
    public int $maxMemory;

    #[Env('SUPERVISOR_GC_EVERY_MINUTES', 1)]
    public int $gcSupervisorEveryMinutes;

    #[Env('HTTP_PROXY_GC_EVERY_MINUTES', 1)]
    public int $gcHttpProxyEveryMinutes;

    #[Env('HTTP_PROXY_MAX_QUERIES', 200)]
    public int $httpProxyMaxQueries = 200;
}

<?php

namespace Anodio\Supervisor\Commands;

use Anodio\Supervisor\Servers\HttpProxyServer;
use DI\Attribute\Inject;
use Symfony\Component\Console\Command\Command;

#[\Anodio\Core\Attributes\Command('supervisor:http-proxy-run', description: 'Run http proxy server')]
class RunHttpProxyServer extends Command
{
    #[Inject]
    public HttpProxyServer $httpProxyServer;

    protected function configure(): void
    {
        $this->setDescription('Run http proxy server');
    }

    protected function execute(\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output): int
    {
        $this->runHttpProxyServer();
        return 0;
    }

    protected function runHttpProxyServer(): bool
    {
        $this->httpProxyServer->run();
        return true;
    }
}

<?php

namespace Anodio\Supervisor\ServiceProviders;

use Anodio\Core\AttributeInterfaces\ServiceProviderInterface;
use Anodio\Core\Attributes\ServiceProvider;

#[ServiceProvider]
class SupervisorServiceProvider implements ServiceProviderInterface
{

    public function register(\DI\ContainerBuilder $containerBuilder): void
    {
        // TODO: Implement register() method.
    }
}

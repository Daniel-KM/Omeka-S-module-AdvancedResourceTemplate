<?php declare(strict_types=1);

namespace AdvancedResourceTemplate\Service\Controller\Admin;

use AdvancedResourceTemplate\Controller\Admin\ResourceTemplateControllerDelegator;
use Psr\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\DelegatorFactoryInterface;

class ResourceTemplateControllerDelegatorFactory implements DelegatorFactoryInterface
{
    public function __invoke(ContainerInterface $services, $name, callable $callback, array $options = null)
    {
        return new ResourceTemplateControllerDelegator($services->get('Omeka\DataTypeManager'));
    }
}

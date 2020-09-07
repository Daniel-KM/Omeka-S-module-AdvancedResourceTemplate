<?php

namespace AdvancedResourceTemplate\Service\Controller\Admin;

use AdvancedResourceTemplate\Controller\Admin\IndexController;
use Interop\Container\ContainerInterface;
use Zend\ServiceManager\Factory\FactoryInterface;

class IndexControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedNamed, array $options = null)
    {
        return new IndexController(
            $services->get('Autofiller\Manager'),
            $services->get('Omeka\EntityManager')
        );
    }
}

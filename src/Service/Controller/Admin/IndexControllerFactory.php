<?php declare(strict_types=1);

namespace AdvancedResourceTemplate\Service\Controller\Admin;

use AdvancedResourceTemplate\Controller\Admin\IndexController;
use Psr\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class IndexControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        $autofillers = $services->get('Config')['advancedresourcetemplate']['autofillers'] ?? [];
        // The autofillers require module Mapper.
        $autofillerFactory = $services->has('Mapper\Mapper')
            ? fn (string $class, array $options) => new $class($services, $options)
            : null;
        return new IndexController(
            $services->get('Omeka\EntityManager'),
            $services->get('MvcTranslator'),
            $autofillers,
            $autofillerFactory
        );
    }
}

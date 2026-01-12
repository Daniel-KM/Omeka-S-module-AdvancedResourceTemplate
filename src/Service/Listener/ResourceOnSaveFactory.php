<?php declare(strict_types=1);

namespace AdvancedResourceTemplate\Service\Listener;

use AdvancedResourceTemplate\Listener\AutomaticValuesHandler;
use AdvancedResourceTemplate\Listener\ResourceOnSave;
use AdvancedResourceTemplate\Listener\ResourceValidator;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ResourceOnSaveFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $api = $services->get('Omeka\ApiManager');
        $easyMeta = $services->get('Common\EasyMeta');
        $connection = $services->get('Omeka\Connection');
        $entityManager = $services->get('Omeka\EntityManager');
        $settings = $services->get('Omeka\Settings');
        $logger = $services->get('Omeka\Logger');
        $status = $services->get('Omeka\Status');

        // Messenger may not be available in all contexts.
        $messenger = null;
        $controllerPlugins = $services->get('ControllerPluginManager');
        if ($controllerPlugins->has('messenger')) {
            $messenger = $controllerPlugins->get('messenger');
        }

        $validator = new ResourceValidator($connection, $logger, $messenger);

        $automaticValuesHandler = new AutomaticValuesHandler(
            $api,
            $easyMeta,
            $connection,
            $services
        );

        return new ResourceOnSave(
            $api,
            $easyMeta,
            $entityManager,
            $settings,
            $status,
            $validator,
            $automaticValuesHandler,
            $services
        );
    }
}

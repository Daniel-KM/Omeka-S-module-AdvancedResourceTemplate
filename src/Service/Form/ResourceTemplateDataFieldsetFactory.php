<?php declare(strict_types=1);

namespace AdvancedResourceTemplate\Service\Form;

use AdvancedResourceTemplate\Form\ResourceTemplateDataFieldset;
use Psr\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ResourceTemplateDataFieldsetFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        $form = new ResourceTemplateDataFieldset(null, $options ?? []);
        $apiAdapterManager = $services->get('Omeka\ApiAdapterManager');
        return $form
            ->setHasAnnotations($apiAdapterManager->has('annotations'))
            ->setHasDigitalObjects($apiAdapterManager->has('digital_objects'))
        ;
    }
}

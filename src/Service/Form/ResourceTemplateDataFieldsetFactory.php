<?php declare(strict_types=1);

namespace AdvancedResourceTemplate\Service\Form;

use AdvancedResourceTemplate\Form\ResourceTemplateDataFieldset;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ResourceTemplateDataFieldsetFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $form = new ResourceTemplateDataFieldset(null, $options ?? []);
        return $form
            ->setHasAnnotations($services->get('Omeka\ApiAdapterManager')->has('annotations'))
        ;
    }
}

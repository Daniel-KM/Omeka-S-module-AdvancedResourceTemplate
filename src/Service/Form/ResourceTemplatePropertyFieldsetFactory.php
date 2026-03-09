<?php declare(strict_types=1);

namespace AdvancedResourceTemplate\Service\Form;

use AdvancedResourceTemplate\Form\ResourceTemplatePropertyFieldset;
use Psr\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ResourceTemplatePropertyFieldsetFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        $form = new ResourceTemplatePropertyFieldset(null, $options ?? []);
        $form->setEventManager($services->get('EventManager'));
        return $form
            ->setDataTypeManager($services->get('Omeka\DataTypeManager'));
    }
}

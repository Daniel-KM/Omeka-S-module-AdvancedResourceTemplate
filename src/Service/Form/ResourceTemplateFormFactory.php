<?php declare(strict_types=1);

namespace AdvancedResourceTemplate\Service\Form;

use AdvancedResourceTemplate\Form\ResourceTemplateForm;
use Psr\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ResourceTemplateFormFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $form = new ResourceTemplateForm(null, $options ?? []);
        $form->setEventManager($services->get('EventManager'));
        return $form;
    }
}

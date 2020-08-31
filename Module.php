<?php

namespace AdvancedResourceTemplate;

if (!class_exists(\Generic\AbstractModule::class)) {
    require file_exists(dirname(__DIR__) . '/Generic/AbstractModule.php')
        ? dirname(__DIR__) . '/Generic/AbstractModule.php'
        : __DIR__ . '/src/Generic/AbstractModule.php';
}

use Generic\AbstractModule;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;

class Module extends AbstractModule
{
    const NAMESPACE = __NAMESPACE__;

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        $sharedEventManager->attach(
            \Omeka\Form\ResourceForm::class,
            'form.add_elements',
            [$this, 'fixResourceForm']
        );
    }

    public function fixResourceForm(Event $event)
    {
        /** @var \Omeka\Form\ResourceForm $form */
        $form = $event->getTarget();
        // Due to the closure (?), the element should be removed first.
        $form
            ->remove('o:resource_template[o:id]')
            ->add([
                'name' => 'o:resource_template[o:id]',
                'type' => \Omeka\Form\Element\ResourceSelect::class,
                'attributes' => [
                    'id' => 'resource-template-select',
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select a template', // @translate
                    'data-api-base-url' => $form->getUrlHelper()->__invoke('api/default', ['resource' => 'resource_templates']),
                ],
                'options' => [
                    'label' => 'Resource template', // @translate
                    'info' => 'A pre-defined template for resource creation.', // @translate
                    'empty_option' => '',
                    'resource_value_options' => [
                        'resource' => 'resource_templates',
                        'query' => [
                            'sort_by' => 'label',
                        ],
                        'option_text_callback' => function ($resourceTemplate) {
                            return $resourceTemplate->label();
                        },
                    ],
                ],
            ]);
    }
}

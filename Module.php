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
use Laminas\Form\Element;
use Omeka\Form\Element\ArrayTextarea;

class Module extends AbstractModule
{
    const NAMESPACE = __NAMESPACE__;

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            'view.layout',
            [$this, 'addAdminResourceHeaders']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\ItemSet',
            'view.layout',
            [$this, 'addAdminResourceHeaders']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Media',
            'view.layout',
            [$this, 'addAdminResourceHeaders']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Annotation',
            'view.layout',
            [$this, 'addAdminResourceHeaders']
        );

        $sharedEventManager->attach(
            \Omeka\Form\ResourceForm::class,
            'form.add_elements',
            [$this, 'fixResourceForm']
        );
        $sharedEventManager->attach(
            \Omeka\Form\ResourceTemplateForm::class,
            'form.add_elements',
            [$this, 'addResourceTemplateFormElements']
        );
        $sharedEventManager->attach(
            \Omeka\Form\ResourceTemplatePropertyFieldset::class,
            'form.add_elements',
            [$this, 'addResourceTemplatePropertyFieldsetElements']
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

    public function addAdminResourceHeaders(Event $event)
    {
        $view = $event->getTarget();

        $action = $view->params()->fromRoute('action');
        if (!in_array($action, ['add', 'edit'])) {
            return;
        }

        $assetUrl = $view->getHelperPluginManager()->get('assetUrl');
        $view->headLink()->appendStylesheet($assetUrl('css/advanced-resource-template-admin.css', 'AdvancedResourceTemplate'));
        $view->headScript()->appendFile($assetUrl('js/advanced-resource-template-admin.js', 'AdvancedResourceTemplate'), 'text/javascript', ['defer' => 'defer']);
    }

    public function addResourceTemplateFormElements(Event $event)
    {
        /** @var \Omeka\Form\ResourceTemplateForm $form */
        $form = $event->getTarget();
        $form->get('o:settings')
            ->add([
                'name' => 'value_languages',
                'type' => ArrayTextarea::class,
                'options' => [
                    'label' => 'Value languages for properties', // @translate
                    'as_key_value' => true,
                ],
                'attributes' => [
                    'id' => 'value_languages',
                ],
            ]);
    }

    public function addResourceTemplatePropertyFieldsetElements(Event $event)
    {
        /** @var \Omeka\Form\ResourceTemplatePropertyFieldset $fieldset */
        $fieldset = $event->getTarget();
        $fieldset
            ->add([
                'name' => 'default_value',
                'type' => Element\Textarea::class,
                'options' => [
                    'label' => 'Default value', // @translate
                ],
                'attributes' => [
                    // 'id' => 'default_value',
                    'class' => 'setting',
                    'data-setting-key' => 'default_value',
                ],
            ])
            ->add([
                'name' => 'value_languages',
                'type' => ArrayTextarea::class,
                'options' => [
                    'label' => 'Suggested languages', // @translate
                    'as_key_value' => true,
                ],
                'attributes' => [
                    // 'id' => 'value_languages',
                    'class' => 'setting',
                    'data-setting-key' => 'value_languages',
                ],
            ]);
    }
}

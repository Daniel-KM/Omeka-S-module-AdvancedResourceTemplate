<?php declare(strict_types=1);

namespace AdvancedResourceTemplate;

return [
    'autofillers' => [
        'factories' => [
            Autofiller\GenericAutofiller::class => Service\Autofiller\AutofillerFactory::class,
            Autofiller\GeonamesAutofiller::class => Service\Autofiller\AutofillerFactory::class,
            Autofiller\IdRefAutofiller::class => Service\Autofiller\AutofillerFactory::class,
        ],
        'aliases' => [
            'generic' => Autofiller\GenericAutofiller::class,
            'geonames' => Autofiller\GeonamesAutofiller::class,
            'idref' => Autofiller\IdRefAutofiller::class,
        ],
    ],
    'service_manager' => [
        'factories' => [
            Autofiller\AutofillerPluginManager::class => Service\Autofiller\AutofillerPluginManagerFactory::class,
        ],
        'aliases' => [
            'Autofiller\Manager' => Autofiller\AutofillerPluginManager::class,
        ],
    ],
    'api_adapters' => [
        'invokables' => [
            'resource_templates' => Api\Adapter\ResourceTemplateAdapter::class,
        ],
    ],
    'permissions' => [
        'acl_resources' => [
            \AdvancedResourceTemplate\Entity\ResourceTemplateData::class,
            \AdvancedResourceTemplate\Entity\ResourceTemplatePropertyData::class,
            'Omeka\Api\Adapter\ResourceTemplateAdapter',
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
        'controller_map' => [
            // Manage the view like the core.
            Controller\Admin\ResourceTemplateControllerDelegator::class => 'omeka/admin/resource-template',
        ],
    ],
    'view_helpers' => [
        'factories' => [
            // TODO To be removed with #omeka/omeka-s/1623.
            'assetUrl' => Service\ViewHelper\AssetUrlFactory::class,
            'dataType' => Service\ViewHelper\DataTypeFactory::class,
        ],
    ],
    'form_elements' => [
        'invokables' => [
            Form\Element\OptionalSelect::class => Form\Element\OptionalSelect::class,
            Form\ResourceTemplatePropertyDataFieldset::class => Form\ResourceTemplatePropertyDataFieldset::class,
            Form\SettingsFieldset::class => Form\SettingsFieldset::class,
            'Omeka\Form\ResourceTemplateImportForm' => Form\ResourceTemplateImportForm::class,
        ],
        'factories' => [
            Form\ResourceTemplateDataFieldset::class => Service\Form\ResourceTemplateDataFieldsetFactory::class,
            'Omeka\Form\Element\DataTypeSelect' => Service\Form\Element\DataTypeSelectFactory::class,
            'Omeka\Form\ResourceTemplateForm' => Service\Form\ResourceTemplateFormFactory::class,
            'Omeka\Form\ResourceTemplatePropertyFieldset' => Service\Form\ResourceTemplatePropertyFieldsetFactory::class,
        ],
        'aliases' => [
            // Use aliases to keep core keys.
            Form\Element\DataTypeSelect::class => 'Omeka\Form\Element\DataTypeSelect',
            Form\ResourceTemplateForm::class => 'Omeka\Form\ResourceTemplateForm',
            Form\ResourceTemplatePropertyFieldset::class => 'Omeka\Form\ResourceTemplatePropertyFieldset',
        ],
    ],
    'controllers' => [
        'factories' => [
            'AdvancedResourceTemplate\Controller\Admin\Index' => Service\Controller\Admin\IndexControllerFactory::class,
        ],
        'delegators' => [
            'Omeka\Controller\Admin\ResourceTemplate' => [Service\Controller\Admin\ResourceTemplateControllerDelegatorFactory::class],
        ],
    ],
    'controller_plugins' => [
        'invokables' => [
            'fieldNameToProperty' => Mvc\Controller\Plugin\FieldNameToProperty::class,
            'mapper' => Mvc\Controller\Plugin\Mapper::class,
            'messenger' => Mvc\Controller\Plugin\Messenger::class,
        ],
        'factories' => [
            'mapperHelper' => Service\ControllerPlugin\MapperHelperFactory::class,
        ],
    ],
    'router' => [
        'routes' => [
            'admin' => [
                'child_routes' => [
                    'values' => [
                        'type' => \Laminas\Router\Http\Literal::class,
                        'options' => [
                            'route' => '/values',
                            'defaults' => [
                                '__NAMESPACE__' => 'AdvancedResourceTemplate\Controller\Admin',
                                'controller' => 'Index',
                                'action' => 'values',
                            ],
                        ],
                    ],
                    'autofiller' => [
                        'type' => \Laminas\Router\Http\Literal::class,
                        'options' => [
                            'route' => '/autofiller',
                            'defaults' => [
                                '__NAMESPACE__' => 'AdvancedResourceTemplate\Controller\Admin',
                                'controller' => 'Index',
                                'action' => 'autofiller',
                            ],
                        ],
                        'may_terminate' => true,
                        'child_routes' => [
                            'settings' => [
                                'type' => \Laminas\Router\Http\Literal::class,
                                'options' => [
                                    'route' => '/settings',
                                    'defaults' => [
                                        'action' => 'autofillerSettings',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => dirname(__DIR__) . '/language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
    'js_translate_strings' => [
        'New item', // @translate
        'New item set', // @translate
        'No results', // @translate
    ],
    'advancedresourcetemplate' => [
        'settings' => [
            // The default autofillers are in /data/mapping/mappings.ini.
            'advancedresourcetemplate_closed_property_list' => '0',
            'advancedresourcetemplate_autofillers' => [],
        ],
    ],
];

<?php declare(strict_types=1);

namespace AdvancedResourceTemplate;

// Properties that have unique values and useless to search for multiple.
$defaultPropertyBlacklist = [
    'dcterms:abstract',
    'dcterms:bibliographicCitation',
    'dcterms:description',
    'dcterms:extent',
    'dcterms:identifier',
    'dcterms:tableOfContents',
    'bibo:abstract',
    'bibo:asin',
    'bibo:content',
    'bibo:doi',
    'bibo:eanucc13',
    'bibo:eissn',
    'bibo:gtin14',
    'bibo:handle',
    'bibo:identifier',
    'bibo:isbn',
    'bibo:isbn10',
    'bibo:isbn13',
    'bibo:issn',
    'bibo:issue',
    'bibo:lccn',
    'bibo:authorList',
    'bibo:contributorList',
    'bibo:editorList',
    'bibo:locator',
    'bibo:number',
    'bibo:oclcnum',
    'bibo:pageEnd',
    'bibo:pageStart',
    'bibo:pages',
    'bibo:pmid',
    'bibo:sici',
    'bibo:upc',
    'bibo:uri',
    'curation:data',
    'curation:note',
    'extracttext:extracted_text',
];

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
    'resource_page_block_layouts' => [
        'invokables' => [
            'metadata' => Site\ResourcePageBlockLayout\Metadata::class,
        ],
    ],
    'form_elements' => [
        'invokables' => [
            Form\ResourceTemplatePropertyDataFieldset::class => Form\ResourceTemplatePropertyDataFieldset::class,
            Form\SettingsFieldset::class => Form\SettingsFieldset::class,
            Form\SiteSettingsFieldset::class => Form\SiteSettingsFieldset::class,
            'Omeka\Form\ResourceTemplateImportForm' => Form\ResourceTemplateImportForm::class,
        ],
        'factories' => [
            Form\ResourceTemplateDataFieldset::class => Service\Form\ResourceTemplateDataFieldsetFactory::class,
            'Omeka\Form\ResourceTemplateForm' => Service\Form\ResourceTemplateFormFactory::class,
            'Omeka\Form\ResourceTemplatePropertyFieldset' => Service\Form\ResourceTemplatePropertyFieldsetFactory::class,
        ],
        'aliases' => [
            // Use aliases to keep core keys.
            Form\ResourceTemplateForm::class => 'Omeka\Form\ResourceTemplateForm',
            Form\ResourceTemplatePropertyFieldset::class => 'Omeka\Form\ResourceTemplatePropertyFieldset',
        ],
    ],
    'controllers' => [
        'factories' => [
            'AdvancedResourceTemplate\Controller\Admin\Index' => Service\Controller\Admin\IndexControllerFactory::class,
        ],
        'delegators' => [
            'Omeka\Controller\Admin\ResourceTemplate' => [
                __NAMESPACE__ => Service\Controller\Admin\ResourceTemplateControllerDelegatorFactory::class,
            ],
        ],
    ],
    'controller_plugins' => [
        'invokables' => [
            'fieldNameToProperty' => Mvc\Controller\Plugin\FieldNameToProperty::class,
        ],
        'factories' => [
            'artMapper' => Service\ControllerPlugin\ArtMapperFactory::class,
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
                'type' => \Laminas\I18n\Translator\Loader\Gettext::class,
                'base_dir' => dirname(__DIR__) . '/language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
    'assets' => [
        // Override internals assets. Only for Omeka assets: modules can use another filename.
        'internals' => [
            'js/global.js' => 'AdvancedResourceTemplate',
            'js/resource-form.js' => 'AdvancedResourceTemplate',
        ],
    ],
    'js_translate_strings' => [
        'New item', // @translate
        'New item set', // @translate
        'New value', // @translate
        'No results', // @translate
    ],
    'advancedresourcetemplate' => [
        'settings' => [
            'advancedresourcetemplate_resource_form_elements' => [
                'metadata_collapse',
                'metadata_description',
                'language',
                'visibility',
                'value_annotation',
                // 'more_actions',
            ],
            'advancedresourcetemplate_skip_checks' => false,
            'advancedresourcetemplate_closed_property_list' => '0',
            'advancedresourcetemplate_skip_private_values' => false,
            'advancedresourcetemplate_properties_display_admin' => false,
            'advancedresourcetemplate_properties_display' => [],
            'advancedresourcetemplate_properties_as_search_whitelist' => [
                'all',
            ],
            'advancedresourcetemplate_properties_as_search_blacklist' => $defaultPropertyBlacklist,
            // The default autofillers are in /data/mapping/mappings.ini.
            'advancedresourcetemplate_autofillers' => [],
            // Hidden settings.
            'advancedresourcetemplate_item_set_queries' => [],
            'advancedresourcetemplate_templates_by_resource' => [],
        ],
        'site_settings' => [
            'advancedresourcetemplate_properties_display_site' => '',
            'advancedresourcetemplate_properties_display' => [],
            'advancedresourcetemplate_properties_as_search_whitelist' => [
                'all',
            ],
            'advancedresourcetemplate_properties_as_search_blacklist' => $defaultPropertyBlacklist,
            'advancedresourcetemplate_block_metadata_fields' => [
                'values-type' => [
                    'dcterms:type' => '',
                ],
                'values-creator' => [
                    'dcterms:creator' => '',
                ],
                'values-date' => [
                    'dcterms:date' => '',
                    'dcterms:created' => '',
                    'dcterms:issued' => '',
                ],
                'values-subject' => [
                    'dcterms:subject' => '',
                ],
                'values-rights = Terms of use' => [
                    'dcterms:rights' => '',
                    'dcterms:license' => '',
                ],
            ],
            'advancedresourcetemplate_block_metadata_show_label' => false,
            'advancedresourcetemplate_block_metadata_show_info' => 'none',
            'advancedresourcetemplate_block_metadata_show_locale' => false,
        ],
    ],
];

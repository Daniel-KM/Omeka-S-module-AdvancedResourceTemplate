<?php declare(strict_types=1);

namespace AdvancedResourceTemplate\Form;

use Common\Form\Element as CommonElement;

trait TraitPropertyValuesDisplay
{
    protected function addElementsPropertyDisplay()
    {
        $this
            ->add([
                'name' => 'advancedresourcetemplate_properties_display',
                'type' => CommonElement\OptionalMultiCheckbox::class,
                'options' => [
                    'element_group' => 'metadata_display',
                    'label' => 'Display of property values', // @translate
                    'value_options' => [
                        'transform' => [
                            'label' => 'Transform value', // @translate
                            'options' => [
                                'value_search' => 'Value as search', // @translate
                                'value_advanced_search' => 'Value as advanced search (module or fallback)', // @translate
                                'value_text_resource' => 'Display linked resource as simple text', // @translate
                                'value_text_uri' => 'Display uri as simple text', // @translate
                            ],
                        ],
                        'prepend' => [
                            'label' => 'Prepend icon', // @translate
                            'options' => [
                                'prepend_icon_search' => 'Prepend an icon for search link', // @translate
                                'prepend_icon_advanced_search' => 'Prepend an icon for advanced search link (module or fallback)', // @translate
                                'prepend_icon_resource' => 'Prepend an icon for linked resource', // @translate
                                'prepend_icon_uri' => 'Prepend an icon for external uri', // @translate
                            ],
                        ],
                        'append' => [
                            'label' => 'Append icon', // @translate
                            'options' => [
                                'append_icon_search' => 'Append an icon for search link', // @translate
                                'append_icon_advanced_search' => 'Append an icon for advanced search link (module or fallback)', // @translate
                                'append_icon_resource' => 'Append an icon for linked resource', // @translate
                                'append_icon_uri' => 'Append an icon for external uri', // @translate
                            ],
                        ],
                        'record_append' => [
                            'label' => 'Append icon only in record', // @translate
                            'options' => [
                                'record_append_icon_search' => 'Append an icon for search link, only in record', // @translate
                                'record_append_icon_advanced_search' => 'Append an icon for advanced search link, only in record (module or fallback)', // @translate
                                'record_append_icon_resource' => 'Append an icon for linked resource, only in record', // @translate
                                'record_append_icon_uri' => 'Append an icon for external uri, only in record', // @translate
                            ],
                        ],
                    ],
                ],
                'attributes' => [
                    'id' => 'advancedresourcetemplate_properties_display',
                ],
            ])
            ->add([
                'name' => 'advancedresourcetemplate_hide_properties',
                'type' => CommonElement\OptionalPropertySelect::class,
                'options' => [
                    'element_group' => 'metadata_display',
                    'label' => 'Properties to hide on public sites', // @translate
                    'info' => 'These properties will be hidden on public pages. This setting is cumulative with per-template settings.', // @translate
                    'term_as_value' => true,
                ],
                'attributes' => [
                    'id' => 'advancedresourcetemplate_hide_properties',
                    'multiple' => true,
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select properties…', // @translate
                ],
            ])
            ->add([
                'name' => 'advancedresourcetemplate_properties_as_search_whitelist',
                'type' => CommonElement\OptionalPropertySelect::class,
                'options' => [
                    'element_group' => 'metadata_display',
                    'label' => 'Properties to display as search link (whitelist)', // @translate
                    'term_as_value' => true,
                    'prepend_value_options' => [
                        'all' => 'All properties', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'advancedresourcetemplate_properties_as_search_whitelist',
                    'multiple' => true,
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select properties…', // @translate
                ],
            ])
            ->add([
                'name' => 'advancedresourcetemplate_properties_as_search_blacklist',
                'type' => CommonElement\OptionalPropertySelect::class,
                'options' => [
                    'element_group' => 'metadata_display',
                    'label' => 'Properties not to display as search link (blacklist)', // @translate
                    'term_as_value' => true,
                ],
                'attributes' => [
                    'id' => 'advancedresourcetemplate_properties_as_search_blacklist',
                    'multiple' => true,
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select properties…', // @translate
                ],
            ])
        ;
        return $this;
    }
}

<?php declare(strict_types=1);

namespace AdvancedResourceTemplate\Form;

use Common\Form\Element as CommonElement;
use Laminas\Form\Element;
use Laminas\Form\Fieldset;
use Omeka\Form\Element as OmekaElement;

class ResourceTemplatePropertyDataFieldset extends Fieldset
{
    public function init(): void
    {
        $this
            ->add([
                'name' => 'min_values',
                'type' => Element\Number::class,
                'options' => [
                    'label' => 'Minimum number of values (when required)', // @translate
                ],
                'attributes' => [
                    // 'id' => 'min_values',
                    'class' => 'setting',
                    'data-setting-key' => 'min_values',
                    'min' => '0',
                    'step' => '1',
                ],
            ])
            ->add([
                'name' => 'max_values',
                'type' => Element\Number::class,
                'options' => [
                    'label' => 'Maximum number of values', // @translate
                ],
                'attributes' => [
                    // 'id' => 'max_values',
                    'class' => 'setting',
                    'data-setting-key' => 'max_values',
                    'min' => '0',
                    'step' => '1',
                ],
            ])
            ->add([
                'name' => 'min_length',
                'type' => Element\Number::class,
                'options' => [
                    'label' => 'Minimum length (characters)', // @translate
                ],
                'attributes' => [
                    // 'id' => 'min_length',
                    'class' => 'setting',
                    'data-setting-key' => 'min_length',
                    'min' => '0',
                    'step' => '1',
                ],
            ])
            ->add([
                'name' => 'max_length',
                'type' => Element\Number::class,
                'options' => [
                    'label' => 'Maximum length (characters)', // @translate
                ],
                'attributes' => [
                    // 'id' => 'max_length',
                    'class' => 'setting',
                    'data-setting-key' => 'max_length',
                    'min' => '0',
                    'step' => '1',
                ],
            ])
            ->add([
                'name' => 'input_control',
                'type' => Element\Text::class,
                'options' => [
                    // Don't include leading and trailing "/", neither start/end
                    // "^$": it's implied.
                    'label' => 'Input control for literal (html pattern)', // @translate
                    'documentation' => 'https://developer.mozilla.org/en-US/docs/Web/HTML/Attributes/pattern',
                ],
                'attributes' => [
                    // 'id' => 'input_control',
                    'class' => 'setting',
                    'data-setting-key' => 'input_control',
                ],
            ])
            ->add([
                'name' => 'default_value',
                'type' => Element\Textarea::class,
                'options' => [
                    // May be a string or a json with keys used in the form:
                    // "@value", "@language", "display_title", "value_resource_id",
                    // "value_resource_name", "url", "thumbnail_url", "@id", "@o:label",
                    // "default" and any other key managed by the data type element.
                    'label' => 'Default value', // @translate
                ],
                'attributes' => [
                    // 'id' => 'default_value',
                    'class' => 'setting',
                    'data-setting-key' => 'default_value',
                ],
            ])
            ->add([
                'name' => 'automatic_value',
                'type' => Element\Textarea::class,
                'options' => [
                    'label' => 'Automatic value (on save)', // @translate
                ],
                'attributes' => [
                    // 'id' => 'automatic_value',
                    'class' => 'setting',
                    'data-setting-key' => 'automatic_value',
                ],
            ])
            ->add([
                'name' => 'automatic_value_issued',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Automatic date when resource is made public', // @translate
                    'value_options' => [
                        '' => 'No', // @translate
                        'first' => 'First time issued', // @translate
                        // TODO Manage last time issued for automatic date?
                        // 'last' => 'Last time issued', // @translate
                    ],
                ],
                'attributes' => [
                    // 'id' => 'automatic_value_issued',
                    'class' => 'setting',
                    'data-setting-key' => 'automatic_value_issued',
                    'value' => '',
                ],
            ])
            ->add([
                'name' => 'display_value',
                'type' => Element\Textarea::class,
                'options' => [
                    'label' => 'Fake value to display when empty', // @translate
                ],
                'attributes' => [
                    // 'id' => 'display_value',
                    'class' => 'setting',
                    'data-setting-key' => 'display_value',
                ],
            ])
            ->add([
                'name' => 'unique_value',
                'type' => CommonElement\OptionalCheckbox::class,
                'options' => [
                    'label' => 'Unique value among resources', // @translate
                    'checked_value' => 'yes',
                ],
                'attributes' => [
                    // 'id' => 'unique_value',
                    'class' => 'setting',
                    'data-setting-key' => 'unique_value',
                ],
            ])
            ->add([
                'name' => 'locked_value',
                'type' => CommonElement\OptionalCheckbox::class,
                'options' => [
                    'label' => 'Locked value once saved', // @translate
                    'checked_value' => 'yes',
                ],
                'attributes' => [
                    // 'id' => 'locked_value',
                    'class' => 'setting',
                    'data-setting-key' => 'locked_value',
                ],
            ])
            ->add([
                'name' => 'property_read_only',
                'type' => CommonElement\OptionalCheckbox::class,
                'options' => [
                    'label' => 'Property disabled in form (editable only by api/job)', // @translate
                    'checked_value' => 'yes',
                ],
                'attributes' => [
                    // 'id' => 'property_read_only',
                    'class' => 'setting',
                    'data-setting-key' => 'property_read_only',
                ],
            ])
            ->add([
                'name' => 'split_separator',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Separator to split a literal value', // @translate
                ],
                'attributes' => [
                    // 'id' => 'split_separator',
                    'class' => 'setting',
                    'data-setting-key' => 'split_separator',
                ],
            ])
            ->add([
                'name' => 'order_by_linked_resource_properties',
                'type' => OmekaElement\ArrayTextarea::class,
                'options' => [
                    'label' => 'Order linked resources by properties', // @translate
                    'as_key_value' => true,
                ],
                'attributes' => [
                    // 'id' => 'value_languages',
                    'class' => 'setting',
                    'data-setting-key' => 'order_by_linked_resource_properties',
                ],
            ])
            ->add([
                'name' => 'resource_query',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Filter linked resources with a query', // @translate
                    'info' => 'Use url arguments of a standard advanced search query', // @translate
                ],
                'attributes' => [
                    // 'id' => 'resource_query',
                    'class' => 'setting',
                    'data-setting-key' => 'resource_query',
                ],
            ])
            ->add([
                'name' => 'quick_new_resource',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Allow quick creation of a resource', // @translate
                    'value_options' => [
                        '' => 'Use template setting', // @translate
                        'yes' => 'Yes', // @translate
                        'no' => 'No', // @translate
                    ],
                    // Fix the formatting issue of the radio label in Omeka.
                    'label_attributes' => [
                        'class' => 'radio',
                    ],
                ],
                'attributes' => [
                    // 'id' => 'quick_new_resource',
                    'class' => 'setting',
                    'data-setting-key' => 'quick_new_resource',
                    'value' => '',
                ],
            ])
            ->add([
                'name' => 'autocomplete',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Autocomplete with existing values', // @translate
                    'value_options' => [
                        '' => 'Use template setting', // @translate
                        'no' => 'No', // @translate
                        'sw' => 'Starts with', // @translate
                        'in' => 'Contains', // @translate
                    ],
                    // Fix the formatting issue of the radio label in Omeka.
                    'label_attributes' => [
                        'class' => 'radio',
                    ],
                ],
                'attributes' => [
                    // 'id' => 'autocomplete',
                    'class' => 'setting',
                    'data-setting-key' => 'autocomplete',
                    'value' => '',
                ],
            ])
            ->add([
                'name' => 'value_languages',
                'type' => OmekaElement\ArrayTextarea::class,
                'options' => [
                    'label' => 'Suggested languages', // @translate
                    'as_key_value' => true,
                ],
                'attributes' => [
                    // 'id' => 'value_languages',
                    'class' => 'setting',
                    'data-setting-key' => 'value_languages',
                ],
            ])
            ->add([
                'name' => 'use_language',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Use language', // @translate
                    'value_options' => [
                        '' => 'Use template setting', // @translate
                        'yes' => 'Yes', // @translate
                        'no' => 'No', // @translate
                    ],
                    // Fix the formatting issue of the radio label in Omeka.
                    'label_attributes' => [
                        'class' => 'radio',
                    ],
                ],
                'attributes' => [
                    // 'id' => 'use_language',
                    'class' => 'setting',
                    'data-setting-key' => 'use_language',
                    'value' => '',
                ],
            ])
            ->add([
                'name' => 'custom_vocab_open',
                'type' => CommonElement\OptionalCheckbox::class,
                'options' => [
                    'label' => 'Custom vocab: open authority list', // @translate
                    'checked_value' => 'yes',
                ],
                'attributes' => [
                    // 'id' => 'custom_vocab_open',
                    'class' => 'setting',
                    'data-setting-key' => 'custom_vocab_open',
                ],
            ])
            ->add([
                'name' => 'value_suggest_keep_original_label',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Value Suggest: keep original label', // @translate
                    'value_options' => [
                        '' => 'Use template setting', // @translate
                        'no' => 'No', // @translate
                        'yes' => 'Yes', // @translate
                    ],
                    // Fix the formatting issue of the radio label in Omeka.
                    'label_attributes' => [
                        'class' => 'radio',
                    ],
                ],
                'attributes' => [
                    // 'id' => 'value_suggest_keep_original_label',
                    'class' => 'setting',
                    'data-setting-key' => 'value_suggest_keep_original_label',
                    'value' => '',
                ],
            ])
            ->add([
                'name' => 'value_suggest_require_uri',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Value Suggest: require uri', // @translate
                    'value_options' => [
                        '' => 'Use template setting', // @translate
                        'no' => 'No', // @translate
                        'yes' => 'Yes', // @translate
                    ],
                    // Fix the formatting issue of the radio label in Omeka.
                    'label_attributes' => [
                        'class' => 'radio',
                    ],
                ],
                'attributes' => [
                    // 'id' => 'value_suggest_require_uri',
                    'class' => 'setting',
                    'data-setting-key' => 'value_suggest_require_uri',
                    'value' => '',
                ],
            ])

            ->add([
                'name' => 'value_annotations_template',
                'type' => CommonElement\OptionalResourceSelect::class,
                'options' => [
                    'label' => 'Value annotations', // @translate
                    'disable_group_by_owner' => true,
                    'prepend_value_options' => [
                        '' => 'Use template setting', // @translate
                        'manual' => 'Manual selection (default)', // @translate
                        'none' => 'No value annotation', // @translate
                    ],
                    'resource_value_options' => [
                        'resource' => 'resource_templates',
                        'query' => ['resource' => 'value_annotations'],
                        'option_text_callback' => fn ($resourceTemplate) => $resourceTemplate->label(),
                    ],
                ],
                'attributes' => [
                    // 'id' => 'value_annotations_templates',
                    'class' => 'chosen-select setting',
                    'data-setting-key' => 'value_annotations_template',
                    'value' => '',
                ],
            ])

            ->add([
                'name' => 'settings',
                'type' => Element\Textarea::class,
                'options' => [
                    'label' => 'More settings', // @translate
                    'info' => 'Allow to pass some settings, usually for theme and generally via key-value pairs or json.', // @translate
                ],
                'attributes' => [
                    // 'id' => 'settings',
                    'class' => 'setting',
                    'data-setting-key' => 'settings',
                ],
            ]);
    }
}

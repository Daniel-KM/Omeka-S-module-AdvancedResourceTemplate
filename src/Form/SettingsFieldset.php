<?php declare(strict_types=1);

namespace AdvancedResourceTemplate\Form;

use Common\Form\Element as CommonElement;
use Laminas\Form\Element;
use Laminas\Form\Fieldset;

class SettingsFieldset extends Fieldset
{
    use TraitPropertyValuesDisplay;

    protected $label = 'Advanced Resource Template'; // @translate

    protected $elementGroups = [
        'resource_edit' => 'Resource edition', // @translate
        'metadata_display' => 'Resource metadata display', // @translate
    ];

    public function init(): void
    {
        $this
            ->setAttribute('id', 'advanded-resource-template')
            ->setOption('element_groups', $this->elementGroups)

            ->add([
                'name' => 'advancedresourcetemplate_skip_checks',
                'type' => Element\Checkbox::class,
                'options' => [
                    'element_group' => 'resource_edit',
                    'label' => 'Skip checking advanced template settings to allow to save an invalid record', // @translate
                    'info' => <<<'TXT'
                        For example if a value is longer than the specified length, it will be saved anyway.
                        This option should be used only during a migration process or to simplify a complex batch edition or import.
                        It does not skip core checks, in particular required properties.
                        TXT, // @translate
                ],
                'attributes' => [
                    'id' => 'advancedresourcetemplate_skip_checks',
                ],
            ])
            ->add([
                'name' => 'advancedresourcetemplate_resource_form_elements',
                'type' => CommonElement\OptionalMultiCheckbox::class,
                'options' => [
                    'element_group' => 'resource_edit',
                    'label' => 'Elements of resource form to display', // @translate
                    'value_options' => [
                        'metadata_collapse' => 'Collapse Metadata description by default', // @translate
                        'metadata_description' => 'Button Metadata description', // @translate
                        'language' => 'Button Language', // @translate
                        'visibility' => 'Button Visibility', // @translate
                        'value_annotation' => 'Button Value annotation', // @translate
                        'more_actions' => 'Button More actions', // @translate
                    ],
                    'use_hidden_element' => true,
                ],
                'attributes' => [
                    'id' => 'advancedresourcetemplate_resource_form_elements',
                ],
            ])
            ->add([
                'name' => 'advancedresourcetemplate_closed_property_list',
                'type' => Element\Radio::class,
                'options' => [
                    'element_group' => 'resource_edit',
                    'label' => 'Append properties to resource form', // @translate
                    'info' => <<<'TXT'
                        When no template is selected in resource form, the property selector may be available or not to force to select a template.
                        Warning: you may have to set each resource template as open/close to addition according to this setting.
                        TXT, // @translate
                    'value_options' => [
                        '0' => 'Allow', // @translate
                        '1' => 'Forbid', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'advancedresourcetemplate_closed_property_list',
                ],
            ])

            // Autofillers feature has been moved to module Mapper.

            ->add([
                'name' => 'advancedresourcetemplate_skip_private_values',
                'type' => Element\Checkbox::class,
                'options' => [
                    'element_group' => 'metadata_display',
                    'label' => 'Skip private values on sites, even for admin', // @translate
                ],
                'attributes' => [
                    'id' => 'advancedresourcetemplate_skip_private_values',
                ],
            ])

            ->add([
                'name' => 'advancedresourcetemplate_properties_display_admin',
                'type' => Element\Checkbox::class,
                'options' => [
                    'element_group' => 'metadata_display',
                    'label' => 'Use property display settings in admin board', // @translate
                ],
                'attributes' => [
                    'id' => 'advancedresourcetemplate_properties_display_admin',
                ],
            ])
            ->addElementsPropertyDisplay()
        ;
    }
}

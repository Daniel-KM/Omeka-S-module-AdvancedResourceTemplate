<?php declare(strict_types=1);

namespace AdvancedResourceTemplate\Form;

use Common\Form\Element as CommonElement;
use Laminas\Form\Fieldset;

class SiteSettingsFieldset extends Fieldset
{
    use TraitPropertyValuesDisplay;

    protected $label = 'Advanced Resource Template'; // @translate

    protected $elementGroups = [
        'block_metadata' => 'Block Resource metadata', // @translate
        'metadata_display' => 'Resource metadata display', // @translate
    ];

    public function init(): void
    {
        $this
            ->setAttribute('id', 'advanded-resource-template')
            ->setOption('element_groups', $this->elementGroups)

            // Display of metadata.

            ->add([
                'name' => 'advancedresourcetemplate_properties_display_site',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'element_group' => 'metadata_display',
                    'label' => 'Mode of display of property values', // @translate
                    'value_options' => [
                        '' => 'Default display', // @translate
                        'main' => 'Use main settings', // @translate
                        'site' => 'Use site settings below', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'advancedresourcetemplate_properties_display_site',
                ],
            ])
            ->addElementsPropertyDisplay()

            ->add([
                'name' => 'advancedresourcetemplate_skip_private_values',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'element_group' => 'metadata_display',
                    'label' => 'Display private values even for admin', // @translate
                    'value_options' => [
                        '' => 'Use main settings', // @translate
                        '0' => 'Show', // @translate
                        '1' => 'Hide', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'advancedresourcetemplate_skip_private_values',
                ],
            ])

            // Block Metadata.

            ->add([
                'name' => 'advancedresourcetemplate_block_metadata_fields',
                'type' => CommonElement\GroupTextarea::class,
                'options' => [
                    'element_group' => 'block_metadata',
                    'label' => 'Metadata for resource block Metadata', // @translate
                    'info' => 'This option may be used to display a short record. Each line is the term and the optional alternatif label. A specific label can be set on each line, separated with a "=". A division to group a list of properties may be added with "# div-class" and an optional label separated with a "=".', // @translate
                    'as_key_value' => true,
                ],
                'attributes' => [
                    'id' => 'advancedresourcetemplate_block_metadata_fields',
                    'rows' => 5,
                    'placeholder' => <<<'TXT'
                        # values-type
                        dcterms:type
                        
                        # values-creator
                        dcterms:creator
                        
                        # values-date
                        dcterms:date
                        dcterms:created
                        dcterms:issued
                        
                        # values-subject
                        dcterms:subject
                        
                        # values-rights = Terms of use
                        dcterms:rights
                        dcterms:license
                        TXT,
                ],
            ])
            ->add([
                'name' => 'advancedresourcetemplate_block_metadata_components',
                'type' => CommonElement\OptionalMultiCheckbox::class,
                'options' => [
                    'element_group' => 'block_metadata',
                    'label' => 'Element for block Metadata', // @translate
                    'value_options' => [
                        'value_label' => 'Metadata label', // @translate
                        'value_term' => 'Property term', // @translate
                        'value_vocab' => 'Vocabulary', // @translate
                        'value_locale' => 'Locale of the value', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'advancedresourcetemplate_block_metadata_components',
                ],
            ])
        ;
    }
}

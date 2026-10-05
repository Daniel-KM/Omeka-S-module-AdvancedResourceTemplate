<?php declare(strict_types=1);

namespace AdvancedResourceTemplateTest\Form;

use AdvancedResourceTemplate\Form\ResourceTemplateDataFieldset;
use AdvancedResourceTemplateTest\AdvancedResourceTemplateTestTrait;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests for the resource types available for a template.
 */
class ResourceTemplateDataFieldsetTest extends AbstractHttpControllerTestCase
{
    use AdvancedResourceTemplateTestTrait;

    /**
     * The concepts of the module Thesaurus can use a template when the module
     * is available, and only in that case.
     */
    public function testConceptsAreAvailableWithThesaurus(): void
    {
        $hasConcepts = $this->getServiceLocator()->get('Omeka\ApiAdapterManager')->has('concepts');

        $fieldset = $this->getServiceLocator()->get('FormElementManager')
            ->get(ResourceTemplateDataFieldset::class);
        $fieldset->init();
        $options = $fieldset->get('use_for_resources')->getValueOptions();

        $this->assertArrayHasKey('items', $options);
        if ($hasConcepts) {
            $this->assertArrayHasKey('concepts', $options);
        } else {
            $this->assertArrayNotHasKey('concepts', $options);
        }
    }
}

<?php declare(strict_types=1);

namespace AdvancedResourceTemplateTest\Listener;

use AdvancedResourceTemplateTest\AdvancedResourceTemplateTestTrait;
use Laminas\EventManager\Event;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests for the order of the values during display.
 *
 * By default, the values are ordered according to the resource template, but a
 * customized view may need another order, so the order of the caller is kept
 * when it lists the properties or asks for it explicitly.
 *
 * @see https://gitlab.com/Daniel-KM/Omeka-S-module-AdvancedResourceTemplate/-/issues/23
 */
class DisplayValuesOrderTest extends AbstractHttpControllerTestCase
{
    use AdvancedResourceTemplateTestTrait;

    /**
     * @var \AdvancedResourceTemplate\Module
     */
    protected $module;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
        $this->module = $this->getServiceLocator()
            ->get('ModuleManager')
            ->getModule('AdvancedResourceTemplate');
    }

    public function tearDown(): void
    {
        $this->cleanupResources();
        $this->logout();
        parent::tearDown();
    }

    /**
     * Without option, the values are ordered according to the template.
     */
    public function testValuesAreOrderedByTemplateByDefault(): void
    {
        [$item, $reversed] = $this->prepareItemWithReversedValues();

        $this->assertSame(
            ['dcterms:title', 'dcterms:description', 'dcterms:subject'],
            $this->displayedTerms($item, $reversed, [])
        );
    }

    /**
     * With "keep_values_order", the order of the caller is kept.
     */
    public function testKeepValuesOrderKeepsTheOrderOfTheCaller(): void
    {
        [$item, $reversed] = $this->prepareItemWithReversedValues();

        $this->assertSame(
            ['dcterms:subject', 'dcterms:description', 'dcterms:title'],
            $this->displayedTerms($item, $reversed, ['keep_values_order' => true])
        );
    }

    /**
     * With "properties", the values follow the order of the list.
     */
    public function testPropertiesOptionKeepsItsOwnOrder(): void
    {
        [$item, $reversed] = $this->prepareItemWithReversedValues();

        $properties = ['dcterms:subject', 'dcterms:title'];
        $this->assertSame(
            $properties,
            $this->displayedTerms($item, $reversed, ['properties' => $properties])
        );
    }

    /**
     * A property that is not in the values is skipped, not appended empty.
     */
    public function testPropertiesOptionSkipsMissingProperty(): void
    {
        [$item, $reversed] = $this->prepareItemWithReversedValues();

        $this->assertSame(
            ['dcterms:subject', 'dcterms:title'],
            $this->displayedTerms($item, $reversed, [
                'properties' => ['dcterms:subject', 'dcterms:date', 'dcterms:title'],
            ])
        );
    }

    /**
     * Create an item with three properties and return its values in the reverse
     * order of the template, like a customized view may do.
     */
    protected function prepareItemWithReversedValues(): array
    {
        $template = $this->createTemplate('Order Template', [], [
            'dcterms:title' => ['data_type' => ['literal']],
            'dcterms:description' => ['data_type' => ['literal']],
            'dcterms:subject' => ['data_type' => ['literal']],
        ]);

        $item = $this->createItem([
            'dcterms:title' => [['type' => 'literal', '@value' => 'Title']],
            'dcterms:description' => [['type' => 'literal', '@value' => 'Description']],
            'dcterms:subject' => [['type' => 'literal', '@value' => 'Subject']],
        ], $template->id());

        $item = $this->api()->read('items', $item->id())->getContent();

        return [$item, array_reverse($item->values(), true)];
    }

    /**
     * Trigger handleResourceDisplayValues and return the ordered terms.
     */
    protected function displayedTerms($item, array $values, array $options): array
    {
        if (!empty($options['properties'])) {
            $values = array_intersect_key($values, array_flip($options['properties']));
        }
        $event = new Event(
            'rep.resource.display_values',
            $item,
            ['values' => $values, 'options' => $options]
        );
        $this->module->handleResourceDisplayValues($event);
        $result = $event->getParam('values');
        return array_keys(is_array($result) ? $result : iterator_to_array($result));
    }
}

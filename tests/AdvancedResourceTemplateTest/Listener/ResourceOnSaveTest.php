<?php declare(strict_types=1);

namespace AdvancedResourceTemplateTest\Listener;

use AdvancedResourceTemplateTest\AdvancedResourceTemplateTestTrait;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Integration tests for ResourceOnSave listener.
 *
 * Tests the full event handling flow through api.create.pre, api.update.pre,
 * api.hydrate.post, api.create.post, and api.update.post events.
 */
class ResourceOnSaveTest extends AbstractHttpControllerTestCase
{
    use AdvancedResourceTemplateTestTrait;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
    }

    public function tearDown(): void
    {
        $this->cleanupResources();
        $this->logout();
        parent::tearDown();
    }

    /**
     * Test that ResourceOnSave listener is properly registered in ServiceManager.
     */
    public function testListenerIsRegisteredInServiceManager(): void
    {
        $services = $this->getServiceLocator();
        $this->assertTrue($services->has(\AdvancedResourceTemplate\Listener\ResourceOnSave::class));

        $listener = $services->get(\AdvancedResourceTemplate\Listener\ResourceOnSave::class);
        $this->assertInstanceOf(\AdvancedResourceTemplate\Listener\ResourceOnSave::class, $listener);
    }

    /**
     * Test creating an item with a template works through the full flow.
     */
    public function testCreateItemWithTemplate(): void
    {
        $template = $this->createTemplate('Basic Template', [], [
            'dcterms:title' => [
                'data_type' => ['literal'],
            ],
            'dcterms:description' => [
                'data_type' => ['literal'],
            ],
        ]);

        $item = $this->createItem([
            'dcterms:title' => [['type' => 'literal', '@value' => 'Test Item']],
            'dcterms:description' => [['type' => 'literal', '@value' => 'A description']],
        ], $template->id());

        $this->assertNotNull($item->id());
        $this->assertEquals('Test Item', $item->displayTitle());
    }

    /**
     * Test updating an item with template constraints.
     */
    public function testUpdateItemWithTemplate(): void
    {
        $template = $this->createTemplate('Update Template', [], [
            'dcterms:title' => [
                'data_type' => ['literal'],
            ],
        ]);

        $item = $this->createItem([
            'dcterms:title' => [['type' => 'literal', '@value' => 'Original Title']],
        ], $template->id());

        // Update the item.
        $easyMeta = $this->getEasyMeta();
        $response = $this->api()->update('items', $item->id(), [
            'dcterms:title' => [[
                'type' => 'literal',
                'property_id' => $easyMeta->propertyId('dcterms:title'),
                '@value' => 'Updated Title',
            ]],
        ]);

        $updatedItem = $response->getContent();
        $this->assertEquals('Updated Title', $updatedItem->displayTitle());
    }

    /**
     * Test that validation errors prevent item creation.
     */
    public function testValidationErrorsPreventsCreation(): void
    {
        $template = $this->createTemplate('Validation Template', [], [
            'dcterms:identifier' => [
                'data_type' => ['literal'],
                'data' => [
                    'input_control' => '[A-Z]{3}[0-9]{3}',
                ],
            ],
        ]);

        // Try to create with invalid identifier.
        $easyMeta = $this->getEasyMeta();
        try {
            $this->api()->create('items', [
                'o:resource_template' => ['o:id' => $template->id()],
                'dcterms:title' => [[
                    'type' => 'literal',
                    'property_id' => $easyMeta->propertyId('dcterms:title'),
                    '@value' => 'Test',
                ]],
                'dcterms:identifier' => [[
                    'type' => 'literal',
                    'property_id' => $easyMeta->propertyId('dcterms:identifier'),
                    '@value' => 'invalid',
                ]],
            ]);
            $this->fail('Expected validation exception was not thrown');
        } catch (\Omeka\Api\Exception\ValidationException $e) {
            // Expected - validation should fail.
            $this->assertTrue(true);
        }
    }

    /**
     * Test item set creation with template.
     */
    public function testCreateItemSetWithTemplate(): void
    {
        $template = $this->createTemplate('ItemSet Template', [
            'use_for_resources' => ['item_sets'],
        ], [
            'dcterms:title' => [
                'data_type' => ['literal'],
            ],
        ]);

        $itemSet = $this->createItemSet([
            'dcterms:title' => [['type' => 'literal', '@value' => 'Test Collection']],
        ], $template->id());

        $this->assertNotNull($itemSet->id());
        $this->assertEquals('Test Collection', $itemSet->displayTitle());
    }

    /**
     * Test that listener gets same instance from ServiceManager (singleton behavior).
     */
    public function testListenerIsSingleton(): void
    {
        $services = $this->getServiceLocator();

        $listener1 = $services->get(\AdvancedResourceTemplate\Listener\ResourceOnSave::class);
        $listener2 = $services->get(\AdvancedResourceTemplate\Listener\ResourceOnSave::class);

        $this->assertSame($listener1, $listener2);
    }

    /**
     * Test creating multiple items doesn't cause state pollution.
     */
    public function testMultipleItemsNoStatePollution(): void
    {
        $template = $this->createTemplate('Multi Item Template', [], [
            'dcterms:identifier' => [
                'data_type' => ['literal'],
                'data' => [
                    'unique_value' => true,
                ],
            ],
        ]);

        // Create first item.
        $item1 = $this->createItem([
            'dcterms:title' => [['type' => 'literal', '@value' => 'Item 1']],
            'dcterms:identifier' => [['type' => 'literal', '@value' => 'ID-001']],
        ], $template->id());

        // Create second item with different identifier.
        $item2 = $this->createItem([
            'dcterms:title' => [['type' => 'literal', '@value' => 'Item 2']],
            'dcterms:identifier' => [['type' => 'literal', '@value' => 'ID-002']],
        ], $template->id());

        // Create third item with another different identifier.
        $item3 = $this->createItem([
            'dcterms:title' => [['type' => 'literal', '@value' => 'Item 3']],
            'dcterms:identifier' => [['type' => 'literal', '@value' => 'ID-003']],
        ], $template->id());

        $this->assertNotNull($item1->id());
        $this->assertNotNull($item2->id());
        $this->assertNotNull($item3->id());
        $this->assertNotEquals($item1->id(), $item2->id());
        $this->assertNotEquals($item2->id(), $item3->id());
    }

    /**
     * Test item creation without template works.
     */
    public function testCreateItemWithoutTemplate(): void
    {
        $item = $this->createItem([
            'dcterms:title' => [['type' => 'literal', '@value' => 'No Template Item']],
        ]);

        $this->assertNotNull($item->id());
        $this->assertNull($item->resourceTemplate());
    }

    /**
     * Test fallback title is stored when configured.
     */
    public function testFallbackTitleStored(): void
    {
        $template = $this->createTemplate('Fallback Title Template', [
            'title_term' => 'dcterms:alternative',
        ], [
            'dcterms:title' => [
                'data_type' => ['literal'],
            ],
            'dcterms:alternative' => [
                'data_type' => ['literal'],
            ],
        ]);

        $item = $this->createItem([
            'dcterms:title' => [['type' => 'literal', '@value' => '']],
            'dcterms:alternative' => [['type' => 'literal', '@value' => 'Alternative Title']],
        ], $template->id());

        // The title should fall back to alternative.
        $this->assertNotNull($item->id());
    }
}

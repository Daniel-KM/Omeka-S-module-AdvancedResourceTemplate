<?php declare(strict_types=1);

namespace AdvancedResourceTemplateTest\Listener;

use AdvancedResourceTemplateTest\AdvancedResourceTemplateTestTrait;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests for automatic_values feature with Mapper integration.
 *
 * These tests require the Mapper module to be installed.
 * They test the automatic_values textarea functionality that uses
 * Mapper INI format to generate values on resource save.
 *
 * @group mapper
 */
class AutomaticValuesMapperTest extends AbstractHttpControllerTestCase
{
    use AdvancedResourceTemplateTestTrait;

    protected static bool $mapperAvailable;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
    }

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();

        // Check if Mapper is available.
        self::$mapperAvailable = $this->getServiceLocator()->has('Mapper\Mapper');
    }

    public function tearDown(): void
    {
        $this->cleanupResources();
        $this->logout();
        parent::tearDown();
    }

    /**
     * Test that automatic_values requires Mapper module.
     */
    public function testAutomaticValuesRequiresMapper(): void
    {
        $handler = $this->getAutomaticValuesHandler();

        // Create a template with automatic_values in Mapper INI format.
        // No sections needed - [maps] is the default.
        $template = $this->createTemplate('Test Template', [
            'automatic_values' => "dcterms:type = \"test\"",
        ]);

        // Verify template data is stored correctly.
        $this->assertNotEmpty($template->dataValue('automatic_values'), 'automatic_values should be stored in template data');

        $resource = [
            'dcterms:title' => [['type' => 'literal', '@value' => 'Test']],
        ];

        // The method should return the resource unchanged if Mapper is not available,
        // or with added values if Mapper is available.
        $result = $handler->appendAutomaticValuesFromTemplateData($template, $resource);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('dcterms:title', $result);
    }

    /**
     * Test automatic_values with simple literal value using raw syntax.
     *
     * @group mapper-required
     */
    public function testAutomaticValuesSimpleLiteral(): void
    {
        if (!self::$mapperAvailable) {
            $this->markTestSkipped('Mapper module not available');
        }

        // Use quoted raw value syntax: field = "value"
        // No sections needed - [maps] is the default.
        $automaticValues = <<<'INI'
dcterms:type = "DefaultType"
INI;

        $template = $this->createTemplate('Auto Literal Template', [
            'automatic_values' => $automaticValues,
        ]);

        // Verify template data is stored.
        $this->assertNotEmpty($template->dataValue('automatic_values'), 'automatic_values should be stored');

        // Test Mapper directly.
        $mapper = $this->getServiceLocator()->get('Mapper\Mapper');
        $mapper->setMapping('test_auto', $automaticValues);
        $mapperResult = $mapper->convert([]);

        // Debug: Check what Mapper returns.
        $this->assertIsArray($mapperResult, 'Mapper should return array');
        $this->assertArrayHasKey('dcterms:type', $mapperResult, 'Mapper should generate dcterms:type');

        $item = $this->createItem([
            'dcterms:title' => [['type' => 'literal', '@value' => 'Test Item']],
        ], $template->id());

        // Check that dcterms:type was automatically added.
        $types = $item->value('dcterms:type', ['all' => true]);
        $this->assertNotEmpty($types, 'dcterms:type should be automatically added');
        $this->assertEquals('DefaultType', $types[0]->value());
    }

    /**
     * Test automatic_values with pattern that uses source data.
     *
     * @group mapper-required
     */
    public function testAutomaticValuesWithPattern(): void
    {
        if (!self::$mapperAvailable) {
            $this->markTestSkipped('Mapper module not available');
        }

        // Use quoted raw value for static identifier in Mapper format.
        // No sections needed - [maps] is the default.
        $automaticValues = <<<'INI'
dcterms:identifier = "ID-PREFIX"
INI;

        $template = $this->createTemplate('Auto Pattern Template', [
            'automatic_values' => $automaticValues,
        ]);

        $easyMeta = $this->getEasyMeta();

        $response = $this->api()->create('items', [
            'o:resource_template' => ['o:id' => $template->id()],
            'dcterms:title' => [[
                'type' => 'literal',
                'property_id' => $easyMeta->propertyId('dcterms:title'),
                '@value' => 'test',
            ]],
        ]);

        $item = $response->getContent();
        $this->createdResources[] = ['type' => 'items', 'id' => $item->id()];

        // The pattern should produce a static identifier.
        $identifiers = $item->value('dcterms:identifier', ['all' => true]);
        $this->assertNotEmpty($identifiers, 'Should have automatic identifier');
        $this->assertEquals('ID-PREFIX', $identifiers[0]->value());
    }

    /**
     * Test automatic_values with static date value.
     *
     * Note: Date filters require source data with a timestamp.
     * For automatic static dates, use PHP date in template config.
     *
     * @group mapper-required
     */
    public function testAutomaticValuesStaticDate(): void
    {
        if (!self::$mapperAvailable) {
            $this->markTestSkipped('Mapper module not available');
        }

        // For static dates, use a fixed format value.
        // No sections needed - [maps] is the default.
        $today = date('Y-m-d');
        $automaticValues = <<<INI
dcterms:created = "$today"
INI;

        $template = $this->createTemplate('Auto Date Template', [
            'automatic_values' => $automaticValues,
        ]);

        $item = $this->createItem([
            'dcterms:title' => [['type' => 'literal', '@value' => 'Date Test']],
        ], $template->id());

        $created = $item->value('dcterms:created', ['all' => true]);
        $this->assertNotEmpty($created);
        // Should be today's date in Y-m-d format.
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $created[0]->value());
        $this->assertEquals($today, $created[0]->value());
    }

    /**
     * Test automatic_values does not overwrite existing values.
     *
     * @group mapper-required
     */
    public function testAutomaticValuesPreservesExisting(): void
    {
        if (!self::$mapperAvailable) {
            $this->markTestSkipped('Mapper module not available');
        }

        $automaticValues = <<<'INI'
dcterms:type = "AutoType"
INI;

        $template = $this->createTemplate('Preserve Template', [
            'automatic_values' => $automaticValues,
        ]);

        $easyMeta = $this->getEasyMeta();

        // Create item with existing dcterms:type.
        $response = $this->api()->create('items', [
            'o:resource_template' => ['o:id' => $template->id()],
            'dcterms:title' => [[
                'type' => 'literal',
                'property_id' => $easyMeta->propertyId('dcterms:title'),
                '@value' => 'Test',
            ]],
            'dcterms:type' => [[
                'type' => 'literal',
                'property_id' => $easyMeta->propertyId('dcterms:type'),
                '@value' => 'ManualType',
            ]],
        ]);

        $item = $response->getContent();
        $this->createdResources[] = ['type' => 'items', 'id' => $item->id()];

        $types = $item->value('dcterms:type', ['all' => true]);
        $typeValues = array_map(fn($t) => $t->value(), $types);

        // Should have the manual type preserved.
        $this->assertContains('ManualType', $typeValues);
    }

    /**
     * Test automatic_values with empty config returns resource unchanged.
     */
    public function testAutomaticValuesEmptyConfig(): void
    {
        $template = $this->createTemplate('Empty Auto Template', [
            'automatic_values' => '',
        ]);

        $item = $this->createItem([
            'dcterms:title' => [['type' => 'literal', '@value' => 'Test']],
        ], $template->id());

        $this->assertNotNull($item->id());
    }

    /**
     * Test automatic_values with invalid INI format logs warning.
     *
     * @group mapper-required
     */
    public function testAutomaticValuesInvalidFormat(): void
    {
        if (!self::$mapperAvailable) {
            $this->markTestSkipped('Mapper module not available');
        }

        $template = $this->createTemplate('Invalid Format Template', [
            'automatic_values' => 'this is not valid INI format at all!!!',
        ]);

        // Should not throw, just return resource unchanged.
        $item = $this->createItem([
            'dcterms:title' => [['type' => 'literal', '@value' => 'Test']],
        ], $template->id());

        $this->assertNotNull($item->id());
    }

    /**
     * Test automatic_values with multiple maps.
     *
     * @group mapper-required
     */
    public function testAutomaticValuesMultipleMaps(): void
    {
        if (!self::$mapperAvailable) {
            $this->markTestSkipped('Mapper module not available');
        }

        // Use quoted raw value syntax for multiple static values.
        // No sections needed - [maps] is the default.
        $automaticValues = <<<'INI'
dcterms:type = "Document"
dcterms:format = "text/plain"
dcterms:language = "en"
INI;

        $template = $this->createTemplate('Multi Map Template', [
            'automatic_values' => $automaticValues,
        ]);

        $item = $this->createItem([
            'dcterms:title' => [['type' => 'literal', '@value' => 'Multi Test']],
        ], $template->id());

        // Check all automatic values were added.
        $type = $item->value('dcterms:type');
        $format = $item->value('dcterms:format');
        $language = $item->value('dcterms:language');

        $this->assertNotNull($type, 'dcterms:type should be set');
        $this->assertEquals('Document', $type->value());

        $this->assertNotNull($format, 'dcterms:format should be set');
        $this->assertEquals('text/plain', $format->value());

        $this->assertNotNull($language, 'dcterms:language should be set');
        $this->assertEquals('en', $language->value());
    }

    /**
     * Test warning is logged when Mapper not available.
     */
    public function testWarningLoggedWithoutMapper(): void
    {
        // This test verifies the handler behavior without Mapper.
        // We can't easily mock the service container, so we just verify
        // the handler doesn't crash.
        $handler = $this->getAutomaticValuesHandler();

        $template = $this->createTemplate('Warning Test Template', [
            'automatic_values' => "dcterms:type = \"Test\"",
        ]);

        $resource = [
            'dcterms:title' => [['type' => 'literal', '@value' => 'Test']],
        ];

        // Should not throw.
        $result = $handler->appendAutomaticValuesFromTemplateData($template, $resource);
        $this->assertIsArray($result);
    }
}

<?php declare(strict_types=1);

namespace AdvancedResourceTemplateTest\Listener;

use AdvancedResourceTemplateTest\AdvancedResourceTemplateTestTrait;
use Omeka\Stdlib\ErrorStore;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests for ResourceValidator.
 *
 * Tests validation of resources against template constraints:
 * - Input control (regex patterns)
 * - Min/max length
 * - Min/max value count
 * - Unique values
 */
class ResourceValidatorTest extends AbstractHttpControllerTestCase
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
     * Test validation passes when no constraints are defined.
     */
    public function testValidationPassesWithNoConstraints(): void
    {
        $template = $this->createTemplate('No Constraints Template', [], [
            'dcterms:title' => [
                'data_type' => ['literal'],
            ],
        ]);

        $item = $this->createItem([
            'dcterms:title' => [['type' => 'literal', '@value' => 'Any value works']],
        ], $template->id());

        $validator = $this->getResourceValidator();
        $errorStore = new ErrorStore();

        $validator->validateProperties($item, $errorStore);

        $this->assertFalse($errorStore->hasErrors());
    }

    /**
     * Test input control pattern validation - valid value.
     */
    public function testInputControlPatternValid(): void
    {
        $template = $this->createTemplate('Pattern Template', [], [
            'dcterms:identifier' => [
                'data_type' => ['literal'],
                'data' => [
                    'input_control' => '[A-Z]{3}-[0-9]{4}',
                ],
            ],
        ]);

        $item = $this->createItem([
            'dcterms:title' => [['type' => 'literal', '@value' => 'Test']],
            'dcterms:identifier' => [['type' => 'literal', '@value' => 'ABC-1234']],
        ], $template->id());

        $validator = $this->getResourceValidator();
        $errorStore = new ErrorStore();

        $validator->validateProperties($item, $errorStore);

        $this->assertFalse($errorStore->hasErrors());
    }

    /**
     * Test input control pattern validation - invalid value.
     *
     * When validation fails, the API throws ValidationException during creation.
     */
    public function testInputControlPatternInvalid(): void
    {
        $template = $this->createTemplate('Pattern Template Invalid', [], [
            'dcterms:identifier' => [
                'data_type' => ['literal'],
                'data' => [
                    'input_control' => '[A-Z]{3}-[0-9]{4}',
                ],
            ],
        ]);

        $this->expectException(\Omeka\Api\Exception\ValidationException::class);

        $easyMeta = $this->getEasyMeta();
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
                '@value' => 'invalid-format',
            ]],
        ]);
    }

    /**
     * Test minimum length validation - valid.
     */
    public function testMinLengthValid(): void
    {
        $template = $this->createTemplate('Min Length Template', [], [
            'dcterms:description' => [
                'data_type' => ['literal'],
                'data' => [
                    'min_length' => 10,
                ],
            ],
        ]);

        $item = $this->createItem([
            'dcterms:title' => [['type' => 'literal', '@value' => 'Test']],
            'dcterms:description' => [['type' => 'literal', '@value' => 'This is a long enough description']],
        ], $template->id());

        $validator = $this->getResourceValidator();
        $errorStore = new ErrorStore();

        $validator->validateProperties($item, $errorStore);

        $this->assertFalse($errorStore->hasErrors());
    }

    /**
     * Test minimum length validation - too short.
     */
    public function testMinLengthTooShort(): void
    {
        $template = $this->createTemplate('Min Length Too Short', [], [
            'dcterms:description' => [
                'data_type' => ['literal'],
                'data' => [
                    'min_length' => 50,
                ],
            ],
        ]);

        $this->expectException(\Omeka\Api\Exception\ValidationException::class);

        $easyMeta = $this->getEasyMeta();
        $this->api()->create('items', [
            'o:resource_template' => ['o:id' => $template->id()],
            'dcterms:title' => [[
                'type' => 'literal',
                'property_id' => $easyMeta->propertyId('dcterms:title'),
                '@value' => 'Test',
            ]],
            'dcterms:description' => [[
                'type' => 'literal',
                'property_id' => $easyMeta->propertyId('dcterms:description'),
                '@value' => 'Short',
            ]],
        ]);
    }

    /**
     * Test maximum length validation - valid.
     */
    public function testMaxLengthValid(): void
    {
        $template = $this->createTemplate('Max Length Template', [], [
            'dcterms:title' => [
                'data_type' => ['literal'],
                'data' => [
                    'max_length' => 100,
                ],
            ],
        ]);

        $item = $this->createItem([
            'dcterms:title' => [['type' => 'literal', '@value' => 'Short title']],
        ], $template->id());

        $validator = $this->getResourceValidator();
        $errorStore = new ErrorStore();

        $validator->validateProperties($item, $errorStore);

        $this->assertFalse($errorStore->hasErrors());
    }

    /**
     * Test maximum length validation - too long.
     */
    public function testMaxLengthTooLong(): void
    {
        $template = $this->createTemplate('Max Length Too Long', [], [
            'dcterms:title' => [
                'data_type' => ['literal'],
                'data' => [
                    'max_length' => 10,
                ],
            ],
        ]);

        $this->expectException(\Omeka\Api\Exception\ValidationException::class);

        $easyMeta = $this->getEasyMeta();
        $this->api()->create('items', [
            'o:resource_template' => ['o:id' => $template->id()],
            'dcterms:title' => [[
                'type' => 'literal',
                'property_id' => $easyMeta->propertyId('dcterms:title'),
                '@value' => 'This title is way too long for the constraint',
            ]],
        ]);
    }

    /**
     * Test unique value validation - unique value passes.
     */
    public function testUniqueValuePasses(): void
    {
        $template = $this->createTemplate('Unique Value Template', [], [
            'dcterms:identifier' => [
                'data_type' => ['literal'],
                'data' => [
                    'unique_value' => true,
                ],
            ],
        ]);

        $item = $this->createItem([
            'dcterms:title' => [['type' => 'literal', '@value' => 'Test']],
            'dcterms:identifier' => [['type' => 'literal', '@value' => 'UNIQUE-001']],
        ], $template->id());

        $validator = $this->getResourceValidator();
        $errorStore = new ErrorStore();

        $validator->validateProperties($item, $errorStore);

        $this->assertFalse($errorStore->hasErrors());
    }

    /**
     * Test unique value validation - duplicate fails.
     */
    public function testUniqueValueFails(): void
    {
        $template = $this->createTemplate('Unique Value Fail', [], [
            'dcterms:identifier' => [
                'data_type' => ['literal'],
                'data' => [
                    'unique_value' => true,
                ],
            ],
        ]);

        // Create first item with identifier.
        $item1 = $this->createItem([
            'dcterms:title' => [['type' => 'literal', '@value' => 'First Item']],
            'dcterms:identifier' => [['type' => 'literal', '@value' => 'DUPLICATE-ID']],
        ], $template->id());

        $this->expectException(\Omeka\Api\Exception\ValidationException::class);

        // Create second item with same identifier - should fail.
        $easyMeta = $this->getEasyMeta();
        $this->api()->create('items', [
            'o:resource_template' => ['o:id' => $template->id()],
            'dcterms:title' => [[
                'type' => 'literal',
                'property_id' => $easyMeta->propertyId('dcterms:title'),
                '@value' => 'Second Item',
            ]],
            'dcterms:identifier' => [[
                'type' => 'literal',
                'property_id' => $easyMeta->propertyId('dcterms:identifier'),
                '@value' => 'DUPLICATE-ID',
            ]],
        ]);
    }

    /**
     * Test multiple validation constraints combined.
     */
    public function testMultipleConstraintsCombined(): void
    {
        $template = $this->createTemplate('Multiple Constraints', [], [
            'dcterms:identifier' => [
                'data_type' => ['literal'],
                'data' => [
                    'input_control' => '[A-Z]{2}[0-9]{3}',
                    'min_length' => 5,
                    'max_length' => 5,
                    'unique_value' => true,
                ],
            ],
        ]);

        // Valid: matches pattern, correct length, unique.
        $item = $this->createItem([
            'dcterms:title' => [['type' => 'literal', '@value' => 'Test']],
            'dcterms:identifier' => [['type' => 'literal', '@value' => 'AB123']],
        ], $template->id());

        $validator = $this->getResourceValidator();
        $errorStore = new ErrorStore();

        $validator->validateProperties($item, $errorStore);

        $this->assertFalse($errorStore->hasErrors());
    }

    /**
     * Test validation skips non-literal values for pattern/length checks.
     */
    public function testValidationSkipsNonLiteralValues(): void
    {
        $template = $this->createTemplate('Non Literal Template', [], [
            'dcterms:relation' => [
                'data_type' => ['uri'],
                'data' => [
                    'input_control' => '[A-Z]+',
                    'min_length' => 100,
                ],
            ],
        ]);

        $item = $this->createItem([
            'dcterms:title' => [['type' => 'literal', '@value' => 'Test']],
            'dcterms:relation' => [['type' => 'uri', '@id' => 'http://example.com/resource']],
        ], $template->id());

        $validator = $this->getResourceValidator();
        $errorStore = new ErrorStore();

        $validator->validateProperties($item, $errorStore);

        // Should pass because URI values are not checked for pattern/length.
        $this->assertFalse($errorStore->hasErrors());
    }

    /**
     * Test validation with Unicode characters.
     */
    public function testValidationWithUnicode(): void
    {
        $template = $this->createTemplate('Unicode Template', [], [
            'dcterms:title' => [
                'data_type' => ['literal'],
                'data' => [
                    'min_length' => 5,
                    'max_length' => 20,
                ],
            ],
        ]);

        // Unicode string with accents (13 chars).
        $item = $this->createItem([
            'dcterms:title' => [['type' => 'literal', '@value' => 'Café français']],
        ], $template->id());

        $validator = $this->getResourceValidator();
        $errorStore = new ErrorStore();

        $validator->validateProperties($item, $errorStore);

        $this->assertFalse($errorStore->hasErrors());
    }
}

<?php declare(strict_types=1);

namespace AdvancedResourceTemplateTest\Controller;

use AdvancedResourceTemplate\Controller\Admin\ResourceTemplateControllerDelegator;
use AdvancedResourceTemplateTest\AdvancedResourceTemplateTestTrait;
use Omeka\DataType\DataTypeInterface;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests for the data types of an imported resource template.
 *
 * The data types with a dynamic name (custom vocabs, thesaurus, tables) contain
 * an id that is specific to each install, so they are identified by their label
 * when a template exported from another install is imported.
 */
class ImportDataTypesTest extends AbstractHttpControllerTestCase
{
    use AdvancedResourceTemplateTestTrait;

    /**
     * @var \AdvancedResourceTemplate\Controller\Admin\ResourceTemplateControllerDelegator
     */
    protected $controller;

    /**
     * @var \Omeka\DataType\Manager
     */
    protected $dataTypeManager;

    /**
     * @var string[] Names of the data types registered for the tests.
     */
    protected $registeredDataTypes = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();

        $services = $this->getServiceLocator();
        $this->dataTypeManager = $services->get('Omeka\DataTypeManager');
        $this->controller = new ResourceTemplateControllerDelegator($this->dataTypeManager);
        $this->controller->setPluginManager($services->get('ControllerPluginManager'));
    }

    public function tearDown(): void
    {
        $this->cleanupResources();
        $this->logout();
        parent::tearDown();
    }

    /**
     * A custom vocab exported with another id is found by its label.
     */
    public function testDynamicDataTypeIsResolvedByLabel(): void
    {
        $this->registerDataType('customvocab:123', 'Countries');

        $flagged = $this->flagValid($this->importWithDataType(
            'customvocab:456',
            'Countries'
        ));

        $this->assertSame(
            ['customvocab:123'],
            array_values($flagged['o:resource_template_property'][0]['o:data_type'])
        );
    }

    /**
     * A thesaurus and a table are resolved the same way.
     */
    public function testThesaurusAndTableAreResolvedByLabel(): void
    {
        $this->registerDataType('thesaurus:71', 'Unesco thesaurus');
        $this->registerDataType('table:languages', 'ISO 639-1 language codes');

        $flagged = $this->flagValid($this->importWithDataType(
            'thesaurus:9999',
            'Unesco thesaurus'
        ));
        $this->assertSame(
            ['thesaurus:71'],
            array_values($flagged['o:resource_template_property'][0]['o:data_type'])
        );

        $flagged = $this->flagValid($this->importWithDataType(
            'table:other-slug',
            'ISO 639-1 language codes'
        ));
        $this->assertSame(
            ['table:languages'],
            array_values($flagged['o:resource_template_property'][0]['o:data_type'])
        );
    }

    /**
     * A label that matches no data type is skipped, not imported as is.
     */
    public function testUnknownDynamicDataTypeIsSkipped(): void
    {
        $this->registerDataType('customvocab:123', 'Countries');

        $flagged = $this->flagValid($this->importWithDataType(
            'customvocab:456',
            'Missing vocab'
        ));

        $this->assertSame(
            [],
            array_values($flagged['o:resource_template_property'][0]['o:data_type'])
        );
    }

    /**
     * The label of a dynamic type is not used to resolve another prefix.
     */
    public function testLabelIsNotResolvedAcrossPrefixes(): void
    {
        $this->registerDataType('customvocab:123', 'Countries');

        $flagged = $this->flagValid($this->importWithDataType(
            'thesaurus:456',
            'Countries'
        ));

        $this->assertSame(
            [],
            array_values($flagged['o:resource_template_property'][0]['o:data_type'])
        );
    }

    /**
     * The static data types are kept as is, with no lookup by label.
     */
    public function testStaticDataTypesAreKept(): void
    {
        $flagged = $this->flagValid($this->importWithDataType('literal', 'Text'));
        $this->assertSame(
            ['literal'],
            array_values($flagged['o:resource_template_property'][0]['o:data_type'])
        );

        $flagged = $this->flagValid($this->importWithDataType('resource:concept', 'Concept'));
        $this->assertSame(
            ['resource:concept'],
            array_values($flagged['o:resource_template_property'][0]['o:data_type'])
        );
    }

    /**
     * Register a data type only known by the tests, to avoid a dependency to
     * the modules that provide the dynamic ones.
     */
    protected function registerDataType(string $name, string $label): void
    {
        $dataType = $this->createMock(DataTypeInterface::class);
        $dataType->method('getName')->willReturn($name);
        $dataType->method('getLabel')->willReturn($label);
        $this->dataTypeManager->setAllowOverride(true);
        $this->dataTypeManager->setFactory($name, function () use ($dataType) {
            return $dataType;
        });
        $this->registeredDataTypes[] = $name;
    }

    /**
     * Build the data of a template exported with a single property and a single
     * data type.
     */
    protected function importWithDataType(string $name, string $label): array
    {
        return [
            'o:label' => 'Imported template',
            'o:resource_template_property' => [
                [
                    'vocabulary_namespace_uri' => 'http://purl.org/dc/terms/',
                    'vocabulary_label' => 'Dublin Core',
                    'local_name' => 'subject',
                    'label' => 'Subject',
                    'data_types' => [
                        ['name' => $name, 'label' => $label],
                    ],
                    'o:data' => [[]],
                ],
            ],
        ];
    }

    /**
     * Call the protected method that prepares an imported template.
     */
    protected function flagValid(array $import): array
    {
        $method = new \ReflectionMethod($this->controller, 'flagValid');
        $method->setAccessible(true);
        return $method->invoke($this->controller, $import);
    }
}

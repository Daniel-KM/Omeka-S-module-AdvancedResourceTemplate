<?php declare(strict_types=1);

namespace AdvancedResourceTemplateTest\Listener;

use AdvancedResourceTemplateTest\AdvancedResourceTemplateTestTrait;
use Laminas\EventManager\Event;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests for the values hidden on public sites.
 *
 * When all the values are filtered, the event must store an empty list, else
 * the original values, private ones included, are displayed.
 */
class HidePrivateValuesTest extends AbstractHttpControllerTestCase
{
    use AdvancedResourceTemplateTestTrait;

    /**
     * @var \AdvancedResourceTemplate\Module
     */
    protected $module;

    /**
     * @var mixed
     */
    protected $originalSkipPrivate;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();

        $services = $this->getServiceLocator();
        $this->module = $services->get('ModuleManager')
            ->getModule('AdvancedResourceTemplate');
        $this->originalSkipPrivate = $services->get('Omeka\Settings')
            ->get('advancedresourcetemplate_skip_private_values');

        $this->setSiteRequest(true);
    }

    public function tearDown(): void
    {
        $this->setSiteRequest(false);
        $this->getServiceLocator()->get('Omeka\Settings')
            ->set('advancedresourcetemplate_skip_private_values', $this->originalSkipPrivate);
        $this->cleanupResources();
        $this->logout();
        parent::tearDown();
    }

    /**
     * With the option, the private values are removed, not the public ones.
     */
    public function testPrivateValuesAreHidden(): void
    {
        $this->setSkipPrivate(true);
        $item = $this->createItemWithPrivateDescription('Public title');

        $values = $this->getFilteredValues($item);
        $this->assertArrayHasKey('dcterms:title', $values);
        $this->assertArrayNotHasKey('dcterms:description', $values);
    }

    /**
     * Without the option, the private values are kept.
     */
    public function testPrivateValuesAreKeptWithoutOption(): void
    {
        $this->setSkipPrivate(false);
        $item = $this->createItemWithPrivateDescription('Public title');

        $values = $this->getFilteredValues($item);
        $this->assertArrayHasKey('dcterms:description', $values);
    }

    /**
     * When all the values are private, no value is displayed at all.
     */
    public function testAllPrivateValuesGiveEmptyList(): void
    {
        $this->setSkipPrivate(true);
        $item = $this->createItemWithPrivateDescription('Public title');
        $this->setValuesPrivate($item->id(), 'dcterms:title');

        $this->assertSame([], $this->getFilteredValues($item));
    }

    /**
     * When all the properties are hidden on the public site, no value is
     * displayed at all.
     */
    public function testAllHiddenPropertiesGiveEmptyList(): void
    {
        $this->setSkipPrivate(false);
        $template = $this->createTemplate('Hide All', [], [
            'dcterms:title' => [
                'data_type' => ['literal'],
                'data' => ['display_on_public_site' => 'no'],
            ],
        ]);
        $item = $this->createItem([
            'dcterms:title' => [
                ['type' => 'literal', '@value' => 'Hidden title'],
            ],
        ], $template->id());

        $this->assertSame([], $this->getFilteredValues($item));
    }

    protected function createItemWithPrivateDescription(string $title)
    {
        $item = $this->createItem([
            'dcterms:title' => [
                ['type' => 'literal', '@value' => $title],
            ],
            'dcterms:description' => [
                ['type' => 'literal', '@value' => 'Private description'],
            ],
        ]);
        $this->setValuesPrivate($item->id(), 'dcterms:description');
        return $item;
    }

    /**
     * Make the values of a property private.
     *
     * The trait does not manage the visibility of the values, so it is set
     * directly in the database.
     */
    protected function setValuesPrivate(int $resourceId, string $term): void
    {
        $this->getConnection()->executeStatement(
            'UPDATE `value` SET `is_public` = 0 WHERE `resource_id` = :resource_id AND `property_id` = :property_id',
            [
                'resource_id' => $resourceId,
                'property_id' => $this->getEasyMeta()->propertyId($term),
            ]
        );
        $this->getEntityManager()->clear();
    }

    protected function setSkipPrivate(bool $skip): void
    {
        // The site setting is not numeric by default, so the main setting is
        // used.
        $this->getServiceLocator()->get('Omeka\Settings')
            ->set('advancedresourcetemplate_skip_private_values', $skip);
    }

    /**
     * Trigger handleResourceDisplayValues and return the filtered values.
     */
    protected function getFilteredValues($item): array
    {
        $item = $this->api()->read('items', $item->id())->getContent();
        $event = new Event(
            'rep.resource.display_values',
            $item,
            ['values' => $item->values(), 'options' => []]
        );
        $this->module->handleResourceDisplayValues($event);
        $result = $event->getParam('values');
        return is_array($result) ? $result : iterator_to_array($result);
    }

    /**
     * Force or unforce isSiteRequest on the Status service.
     */
    protected function setSiteRequest(bool $isSite): void
    {
        $status = $this->getServiceLocator()->get('Omeka\Status');
        $ref = new \ReflectionProperty($status, 'isSiteRequest');
        $ref->setAccessible(true);
        if ($isSite) {
            $this->getServiceLocator()->get('Omeka\Settings\Site')->setTargetId(1);
            $ref->setValue($status, true);
        } else {
            $ref->setValue($status, null);
        }
    }
}

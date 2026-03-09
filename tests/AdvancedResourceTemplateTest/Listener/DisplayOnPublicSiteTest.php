<?php declare(strict_types=1);

namespace AdvancedResourceTemplateTest\Listener;

use AdvancedResourceTemplateTest\AdvancedResourceTemplateTestTrait;
use Laminas\EventManager\Event;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests for the display_on_public_site template property setting.
 *
 * Verifies that properties configured with display_on_public_site
 * are correctly filtered during public display depending on
 * authentication status.
 */
class DisplayOnPublicSiteTest extends AbstractHttpControllerTestCase
{
    use AdvancedResourceTemplateTestTrait;

    /**
     * @var \AdvancedResourceTemplate\Module
     */
    protected $module;

    /**
     * @var bool Original isSiteRequest value.
     */
    protected $originalIsSiteRequest;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();

        $services = $this->getServiceLocator();
        $this->module = $services->get('ModuleManager')
            ->getModule('AdvancedResourceTemplate');

        // Force site request context for all tests.
        $this->setSiteRequest(true);
    }

    public function tearDown(): void
    {
        $this->setSiteRequest(false);
        $this->cleanupResources();
        $this->logout();
        parent::tearDown();
    }

    /**
     * Default (empty): property is visible on public site.
     */
    public function testDefaultDisplayShowsValues(): void
    {
        $template = $this->createTemplate('Display Default', [], [
            'dcterms:title' => [
                'data_type' => ['literal'],
            ],
            'dcterms:description' => [
                'data_type' => ['literal'],
                'data' => ['display_on_public_site' => ''],
            ],
        ]);

        $item = $this->createItem([
            'dcterms:title' => [
                ['type' => 'literal', '@value' => 'Test'],
            ],
            'dcterms:description' => [
                ['type' => 'literal', '@value' => 'Visible'],
            ],
        ], $template->id());

        $values = $this->getFilteredValues($item);
        $this->assertArrayHasKey('dcterms:description', $values);
    }

    /**
     * display_on_public_site = 'no': property is hidden for everyone.
     */
    public function testDisplayNoHidesValues(): void
    {
        $template = $this->createTemplate('Display No', [], [
            'dcterms:title' => [
                'data_type' => ['literal'],
            ],
            'dcterms:description' => [
                'data_type' => ['literal'],
                'data' => ['display_on_public_site' => 'no'],
            ],
        ]);

        $item = $this->createItem([
            'dcterms:title' => [
                ['type' => 'literal', '@value' => 'Test'],
            ],
            'dcterms:description' => [
                ['type' => 'literal', '@value' => 'Hidden'],
            ],
        ], $template->id());

        // Hidden even for authenticated users.
        $values = $this->getFilteredValues($item);
        $this->assertArrayNotHasKey('dcterms:description', $values);
    }

    /**
     * display_on_public_site = 'authenticated': hidden for anonymous.
     */
    public function testDisplayAuthenticatedHidesForAnonymous(): void
    {
        $template = $this->createTemplate('Display Auth', [], [
            'dcterms:title' => [
                'data_type' => ['literal'],
            ],
            'dcterms:description' => [
                'data_type' => ['literal'],
                'data' => ['display_on_public_site' => 'authenticated'],
            ],
        ]);

        $item = $this->createItem([
            'dcterms:title' => [
                ['type' => 'literal', '@value' => 'Test'],
            ],
            'dcterms:description' => [
                ['type' => 'literal', '@value' => 'Auth only'],
            ],
        ], $template->id());

        // Logout to simulate anonymous user.
        $this->logout();

        $values = $this->getFilteredValues($item);
        $this->assertArrayNotHasKey('dcterms:description', $values);
    }

    /**
     * display_on_public_site = 'authenticated': visible for logged-in.
     */
    public function testDisplayAuthenticatedShowsForLoggedIn(): void
    {
        $template = $this->createTemplate('Display Auth Visible', [], [
            'dcterms:title' => [
                'data_type' => ['literal'],
            ],
            'dcterms:description' => [
                'data_type' => ['literal'],
                'data' => ['display_on_public_site' => 'authenticated'],
            ],
        ]);

        $item = $this->createItem([
            'dcterms:title' => [
                ['type' => 'literal', '@value' => 'Test'],
            ],
            'dcterms:description' => [
                ['type' => 'literal', '@value' => 'Auth only'],
            ],
        ], $template->id());

        // User is logged in (setUp does loginAdmin).
        $values = $this->getFilteredValues($item);
        $this->assertArrayHasKey('dcterms:description', $values);
    }

    /**
     * Multiple properties with different display settings.
     */
    public function testMixedDisplaySettings(): void
    {
        $template = $this->createTemplate('Display Mixed', [], [
            'dcterms:title' => [
                'data_type' => ['literal'],
            ],
            'dcterms:description' => [
                'data_type' => ['literal'],
                'data' => ['display_on_public_site' => 'no'],
            ],
            'dcterms:subject' => [
                'data_type' => ['literal'],
                'data' => ['display_on_public_site' => 'authenticated'],
            ],
            'dcterms:creator' => [
                'data_type' => ['literal'],
                'data' => ['display_on_public_site' => ''],
            ],
        ]);

        $item = $this->createItem([
            'dcterms:title' => [
                ['type' => 'literal', '@value' => 'Test'],
            ],
            'dcterms:description' => [
                ['type' => 'literal', '@value' => 'Hidden'],
            ],
            'dcterms:subject' => [
                ['type' => 'literal', '@value' => 'Auth only'],
            ],
            'dcterms:creator' => [
                ['type' => 'literal', '@value' => 'Visible'],
            ],
        ], $template->id());

        // Logged in: description hidden, subject and creator visible.
        $values = $this->getFilteredValues($item);
        $this->assertArrayHasKey('dcterms:title', $values);
        $this->assertArrayNotHasKey('dcterms:description', $values);
        $this->assertArrayHasKey('dcterms:subject', $values);
        $this->assertArrayHasKey('dcterms:creator', $values);

        // Anonymous: description and subject hidden, creator visible.
        $this->logout();
        $values = $this->getFilteredValues($item);
        $this->assertArrayHasKey('dcterms:title', $values);
        $this->assertArrayNotHasKey('dcterms:description', $values);
        $this->assertArrayNotHasKey('dcterms:subject', $values);
        $this->assertArrayHasKey('dcterms:creator', $values);
    }

    /**
     * Item without template: no filtering applied.
     */
    public function testItemWithoutTemplateIsUnfiltered(): void
    {
        $item = $this->createItem([
            'dcterms:title' => [
                ['type' => 'literal', '@value' => 'Test'],
            ],
            'dcterms:description' => [
                ['type' => 'literal', '@value' => 'Visible'],
            ],
        ]);

        $values = $this->getFilteredValues($item);
        $this->assertArrayHasKey('dcterms:description', $values);
    }

    /**
     * Non-site request: no filtering applied regardless of setting.
     */
    public function testNonSiteRequestIsUnfiltered(): void
    {
        $template = $this->createTemplate('Display Admin', [], [
            'dcterms:title' => [
                'data_type' => ['literal'],
            ],
            'dcterms:description' => [
                'data_type' => ['literal'],
                'data' => ['display_on_public_site' => 'no'],
            ],
        ]);

        $item = $this->createItem([
            'dcterms:title' => [
                ['type' => 'literal', '@value' => 'Test'],
            ],
            'dcterms:description' => [
                ['type' => 'literal', '@value' => 'Admin visible'],
            ],
        ], $template->id());

        // Admin context (not a site request).
        $this->setSiteRequest(false);
        $values = $this->getFilteredValues($item);
        $this->assertArrayHasKey('dcterms:description', $values);
    }

    /**
     * Trigger handleResourceDisplayValues and return the filtered
     * values array.
     */
    protected function getFilteredValues($item): array
    {
        // Re-read representation to ensure fresh data.
        $item = $this->api()->read('items', $item->id())->getContent();

        $values = $item->values();

        $event = new Event(
            'rep.resource.display_values',
            $item,
            ['values' => $values, 'options' => []]
        );

        $this->module->handleResourceDisplayValues($event);

        return $event->getParam('values');
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
            // Set a site for SiteSettings service.
            $siteSettings = $this->getServiceLocator()
                ->get('Omeka\Settings\Site');
            $siteSettings->setTargetId(1);
            $ref->setValue($status, true);
        } else {
            $ref->setValue($status, null);
        }
    }
}

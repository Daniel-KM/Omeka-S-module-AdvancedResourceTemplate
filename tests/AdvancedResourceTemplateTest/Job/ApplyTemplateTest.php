<?php declare(strict_types=1);

namespace AdvancedResourceTemplateTest\Job;

use AdvancedResourceTemplateTest\AdvancedResourceTemplateTestTrait;
use Omeka\Test\AbstractHttpControllerTestCase;

class ApplyTemplateTest extends AbstractHttpControllerTestCase
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
     * Dispatch the ApplyTemplate job synchronously.
     *
     * Re-merges the authenticated user into the entity manager
     * before dispatching, since previous EM clears may have
     * detached it.
     */
    protected function dispatchApplyTemplate(
        int $templateId,
        bool $fix,
        array $options = []
    ): void {
        $services = $this->getServiceLocator();

        // Ensure the auth identity is managed by the EM,
        // because previous clear() calls may have detached it.
        $em = $services->get('Omeka\EntityManager');
        $auth = $services->get('Omeka\AuthenticationService');
        $user = $auth->getIdentity();
        if ($user && !$em->contains($user)) {
            $user = $em->merge($user);
            $auth->getStorage()->write($user);
        }

        $dispatcher = $services->get('Omeka\Job\Dispatcher');
        $args = array_merge([
            'template_id' => $templateId,
            'fix' => $fix,
            'fix_default_values' => false,
            'fix_automatic_values' => false,
            'fix_truncate' => false,
            'fix_max_values' => false,
        ], $options);
        $job = $dispatcher->dispatch(
            \AdvancedResourceTemplate\Job\ApplyTemplate::class,
            $args,
            $services->get('Omeka\Job\DispatchStrategy\Synchronous')
        );
        $this->assertNotEquals(
            \Omeka\Entity\Job::STATUS_ERROR,
            $job->getStatus(),
            'ApplyTemplate job should not error'
        );
    }

    /**
     * Re-read an item from the API to get fresh data.
     */
    protected function reloadItem(int $id): \Omeka\Api\Representation\ItemRepresentation
    {
        // Clear entity manager to force fresh read.
        $this->getEntityManager()->clear();
        return $this->api()->read('items', $id)->getContent();
    }

    /**
     * Assign a template to a resource directly via SQL, bypassing
     * validation so that items with non-conforming values can be
     * tested against the ApplyTemplate job.
     */
    protected function assignTemplateDirectly(
        int $resourceId,
        int $templateId
    ): void {
        $this->getConnection()->executeStatement(
            'UPDATE resource SET resource_template_id = :tid WHERE id = :rid',
            ['tid' => $templateId, 'rid' => $resourceId]
        );
    }

    /**
     * Audit mode must not modify resources.
     */
    public function testAuditModeDoesNotModifyResources(): void
    {
        $template = $this->createTemplate('Audit Template', [], [
            'dcterms:title' => [
                'data_type' => ['literal'],
                'data' => ['max_length' => '5'],
            ],
        ]);

        // Create item without template to bypass validation.
        $item = $this->createItem([
            'dcterms:title' => [
                ['type' => 'literal', '@value' => 'Too long title'],
            ],
        ]);
        $this->assignTemplateDirectly($item->id(), $template->id());

        $this->dispatchApplyTemplate($template->id(), false);

        $updated = $this->reloadItem($item->id());
        $this->assertSame(
            'Too long title',
            $updated->value('dcterms:title')->value()
        );
    }

    /**
     * Fix mode with fix_truncate truncates values exceeding
     * max_length.
     */
    public function testFixTruncatesTooLongValues(): void
    {
        $template = $this->createTemplate('Truncate Template', [], [
            'dcterms:title' => [
                'data_type' => ['literal'],
                'data' => ['max_length' => '5'],
            ],
        ]);

        $item = $this->createItem([
            'dcterms:title' => [
                ['type' => 'literal', '@value' => 'Too long title'],
            ],
        ]);
        $this->assignTemplateDirectly($item->id(), $template->id());

        $this->dispatchApplyTemplate($template->id(), true, [
            'fix_truncate' => true,
        ]);

        $updated = $this->reloadItem($item->id());
        $this->assertSame(
            'Too l',
            $updated->value('dcterms:title')->value()
        );
    }

    /**
     * Fix mode with fix_default_values adds a default value to
     * an empty required property.
     */
    public function testFixAddsDefaultValues(): void
    {
        $template = $this->createTemplate('Default Value Template', [], [
            'dcterms:title' => [
                'data_type' => ['literal'],
            ],
            'dcterms:description' => [
                'data_type' => ['literal'],
                'is_required' => true,
                'data' => ['default_value' => 'Default desc'],
            ],
        ]);

        // Create item without template to bypass required check.
        $item = $this->createItem([
            'dcterms:title' => [
                ['type' => 'literal', '@value' => 'Item without desc'],
            ],
        ]);
        $this->assignTemplateDirectly($item->id(), $template->id());

        // Confirm no description before job.
        $reloaded = $this->reloadItem($item->id());
        $this->assertNull($reloaded->value('dcterms:description'));

        $this->dispatchApplyTemplate($template->id(), true, [
            'fix_default_values' => true,
        ]);

        $updated = $this->reloadItem($item->id());
        $desc = $updated->value('dcterms:description');
        $this->assertNotNull($desc, 'Description should have been added');
        $this->assertSame('Default desc', $desc->value());
    }

    /**
     * Fix mode with fix_max_values removes excess values.
     */
    public function testFixRemovesExcessValues(): void
    {
        $template = $this->createTemplate('Max Values Template', [], [
            'dcterms:title' => [
                'data_type' => ['literal'],
            ],
            'dcterms:subject' => [
                'data_type' => ['literal'],
                'data' => ['max_values' => '2'],
            ],
        ]);

        // Create item without template to bypass max_values.
        $item = $this->createItem([
            'dcterms:title' => [
                ['type' => 'literal', '@value' => 'Item with subjects'],
            ],
            'dcterms:subject' => [
                ['type' => 'literal', '@value' => 'Subject A'],
                ['type' => 'literal', '@value' => 'Subject B'],
                ['type' => 'literal', '@value' => 'Subject C'],
            ],
        ]);
        $this->assignTemplateDirectly($item->id(), $template->id());

        // Confirm 3 subjects before job.
        $reloaded = $this->reloadItem($item->id());
        $subjects = $reloaded->values()['dcterms:subject']['values'] ?? [];
        $this->assertCount(3, $subjects);

        $this->dispatchApplyTemplate($template->id(), true, [
            'fix_max_values' => true,
        ]);

        $updated = $this->reloadItem($item->id());
        $subjects = $updated->values()['dcterms:subject']['values'] ?? [];
        $this->assertCount(2, $subjects);
    }

    /**
     * A template with constraints but no matching resources
     * should not throw.
     */
    public function testTemplateWithNoResources(): void
    {
        $template = $this->createTemplate('Empty Template', [], [
            'dcterms:title' => [
                'data_type' => ['literal'],
                'data' => ['max_length' => '10'],
            ],
        ]);

        // No resources created with this template.
        $this->dispatchApplyTemplate($template->id(), true, [
            'fix_truncate' => true,
        ]);

        // No exception means success.
        $this->assertTrue(true);
    }

    /**
     * Audit mode reports wrong data types without fixing.
     */
    public function testAuditReportsWrongDataTypes(): void
    {
        $template = $this->createTemplate('DT Audit', [], [
            'dcterms:title' => [
                'data_type' => ['literal'],
            ],
            'dcterms:subject' => [
                'data_type' => ['uri'],
            ],
        ]);

        // Create item with literal subject (wrong type for
        // template that expects uri).
        $item = $this->createItem([
            'dcterms:title' => [
                ['type' => 'literal', '@value' => 'Test'],
            ],
            'dcterms:subject' => [
                ['type' => 'literal', '@value' => 'Not a URI'],
            ],
        ]);
        $this->assignTemplateDirectly(
            $item->id(), $template->id()
        );

        $this->dispatchApplyTemplate($template->id(), false);

        // Value should remain unchanged in audit mode.
        $updated = $this->reloadItem($item->id());
        $subject = $updated->value('dcterms:subject');
        $this->assertNotNull($subject);
        $this->assertSame('literal', $subject->type());
        $this->assertSame('Not a URI', $subject->value());
    }

    /**
     * Fix converts a URI value to literal when template only
     * allows literal.
     */
    public function testFixConvertsUriToLiteral(): void
    {
        $template = $this->createTemplate('DT Uri2Lit', [], [
            'dcterms:title' => [
                'data_type' => ['literal'],
            ],
            'dcterms:source' => [
                'data_type' => ['literal'],
            ],
        ]);

        // Create item with URI source, then assign template
        // that only allows literal.
        $item = $this->createItem([
            'dcterms:title' => [
                ['type' => 'literal', '@value' => 'Test'],
            ],
            'dcterms:source' => [
                ['type' => 'uri', '@id' => 'http://example.org/source'],
            ],
        ]);
        $this->assignTemplateDirectly(
            $item->id(), $template->id()
        );

        $this->dispatchApplyTemplate($template->id(), true, [
            'fix_data_types' => true,
        ]);

        $updated = $this->reloadItem($item->id());
        $source = $updated->value('dcterms:source');
        $this->assertNotNull($source);
        $this->assertSame('literal', $source->type());
        $this->assertSame(
            'http://example.org/source', $source->value()
        );
    }

    /**
     * Fix converts a literal URL value to URI when template
     * only allows uri.
     */
    public function testFixConvertsLiteralUrlToUri(): void
    {
        $template = $this->createTemplate('DT Lit2Uri', [], [
            'dcterms:title' => [
                'data_type' => ['literal'],
            ],
            'dcterms:source' => [
                'data_type' => ['uri'],
            ],
        ]);

        $item = $this->createItem([
            'dcterms:title' => [
                ['type' => 'literal', '@value' => 'Test'],
            ],
            'dcterms:source' => [
                ['type' => 'literal', '@value' => 'http://example.org/page'],
            ],
        ]);
        $this->assignTemplateDirectly(
            $item->id(), $template->id()
        );

        $this->dispatchApplyTemplate($template->id(), true, [
            'fix_data_types' => true,
        ]);

        $updated = $this->reloadItem($item->id());
        $source = $updated->value('dcterms:source');
        $this->assertNotNull($source);
        $this->assertSame('uri', $source->type());
        $this->assertSame(
            'http://example.org/page', $source->uri()
        );
    }

    /**
     * Fix cannot convert a non-URL literal to URI: value stays
     * unchanged.
     */
    public function testFixCannotConvertNonUrlLiteralToUri(): void
    {
        $template = $this->createTemplate('DT NoConvert', [], [
            'dcterms:title' => [
                'data_type' => ['literal'],
            ],
            'dcterms:source' => [
                'data_type' => ['uri'],
            ],
        ]);

        $item = $this->createItem([
            'dcterms:title' => [
                ['type' => 'literal', '@value' => 'Test'],
            ],
            'dcterms:source' => [
                ['type' => 'literal', '@value' => 'not a url at all'],
            ],
        ]);
        $this->assignTemplateDirectly(
            $item->id(), $template->id()
        );

        $this->dispatchApplyTemplate($template->id(), true, [
            'fix_data_types' => true,
        ]);

        // Value should remain literal (not convertible).
        $updated = $this->reloadItem($item->id());
        $source = $updated->value('dcterms:source');
        $this->assertNotNull($source);
        $this->assertSame('literal', $source->type());
    }

    /**
     * Fix converts a literal to resource:item when the value
     * is a valid resource ID.
     */
    public function testFixConvertsLiteralToResourceItem(): void
    {
        // Create a target item to be referenced.
        $target = $this->createItem([
            'dcterms:title' => [
                ['type' => 'literal', '@value' => 'Target'],
            ],
        ]);

        $template = $this->createTemplate('DT Lit2Res', [], [
            'dcterms:title' => [
                'data_type' => ['literal'],
            ],
            'dcterms:relation' => [
                'data_type' => ['resource:item'],
            ],
        ]);

        // Create item with literal value = target item id.
        $item = $this->createItem([
            'dcterms:title' => [
                ['type' => 'literal', '@value' => 'Test'],
            ],
            'dcterms:relation' => [
                [
                    'type' => 'literal',
                    '@value' => (string) $target->id(),
                ],
            ],
        ]);
        $this->assignTemplateDirectly(
            $item->id(), $template->id()
        );

        $this->dispatchApplyTemplate($template->id(), true, [
            'fix_data_types' => true,
        ]);

        $updated = $this->reloadItem($item->id());
        $relation = $updated->value('dcterms:relation');
        $this->assertNotNull($relation);
        $this->assertSame('resource:item', $relation->type());
        $this->assertSame(
            $target->id(),
            $relation->valueResource()->id()
        );
    }

    /**
     * Fix respects resource sub-type: resource:item rejects a
     * media ID.
     */
    public function testFixRejectsWrongResourceSubType(): void
    {
        // Create an item with a media to get a media ID.
        $item = $this->createItem([
            'dcterms:title' => [
                ['type' => 'literal', '@value' => 'Item with media'],
            ],
        ]);
        // Create a media on this item.
        $mediaData = [
            'o:item' => ['o:id' => $item->id()],
            'o:ingester' => 'html',
            'html' => '<p>test</p>',
            'dcterms:title' => [
                [
                    'type' => 'literal',
                    'property_id' => $this->getEasyMeta()
                        ->propertyId('dcterms:title'),
                    '@value' => 'Test Media',
                ],
            ],
        ];
        $media = $this->api()->create('media', $mediaData)
            ->getContent();
        $this->createdResources[] = [
            'type' => 'media', 'id' => $media->id(),
        ];

        $template = $this->createTemplate('DT SubType', [], [
            'dcterms:title' => [
                'data_type' => ['literal'],
            ],
            'dcterms:relation' => [
                'data_type' => ['resource:item'],
            ],
        ]);

        // Create item referencing the media as resource (wrong
        // sub-type for resource:item).
        $source = $this->createItem([
            'dcterms:title' => [
                ['type' => 'literal', '@value' => 'Source'],
            ],
            'dcterms:relation' => [
                [
                    'type' => 'resource',
                    'value_resource_id' => $media->id(),
                ],
            ],
        ]);
        $this->assignTemplateDirectly(
            $source->id(), $template->id()
        );

        $this->dispatchApplyTemplate($template->id(), true, [
            'fix_data_types' => true,
        ]);

        // Should remain resource type (media cannot become
        // resource:item).
        $updated = $this->reloadItem($source->id());
        $relation = $updated->value('dcterms:relation');
        $this->assertNotNull($relation);
        $this->assertSame('resource', $relation->type());
    }

    /**
     * Fix converts resource to resource:item when the linked
     * resource is actually an item.
     */
    public function testFixConvertsResourceToResourceItem(): void
    {
        $target = $this->createItem([
            'dcterms:title' => [
                ['type' => 'literal', '@value' => 'Target Item'],
            ],
        ]);

        $template = $this->createTemplate('DT Res2Item', [], [
            'dcterms:title' => [
                'data_type' => ['literal'],
            ],
            'dcterms:relation' => [
                'data_type' => ['resource:item'],
            ],
        ]);

        // Create item with generic "resource" type linking to
        // an item.
        $item = $this->createItem([
            'dcterms:title' => [
                ['type' => 'literal', '@value' => 'Source'],
            ],
            'dcterms:relation' => [
                [
                    'type' => 'resource',
                    'value_resource_id' => $target->id(),
                ],
            ],
        ]);
        $this->assignTemplateDirectly(
            $item->id(), $template->id()
        );

        $this->dispatchApplyTemplate($template->id(), true, [
            'fix_data_types' => true,
        ]);

        $updated = $this->reloadItem($item->id());
        $relation = $updated->value('dcterms:relation');
        $this->assertNotNull($relation);
        $this->assertSame('resource:item', $relation->type());
        $this->assertSame(
            $target->id(),
            $relation->valueResource()->id()
        );
    }

    /**
     * Fix removes extra properties not in template.
     */
    public function testFixRemovesExtraProperties(): void
    {
        $template = $this->createTemplate('DT Extra', [], [
            'dcterms:title' => [
                'data_type' => ['literal'],
            ],
        ]);

        $item = $this->createItem([
            'dcterms:title' => [
                ['type' => 'literal', '@value' => 'Test'],
            ],
            'dcterms:subject' => [
                ['type' => 'literal', '@value' => 'Extra prop'],
            ],
        ]);
        $this->assignTemplateDirectly(
            $item->id(), $template->id()
        );

        $this->dispatchApplyTemplate($template->id(), true, [
            'fix_extra_properties' => true,
        ]);

        $updated = $this->reloadItem($item->id());
        $this->assertNull($updated->value('dcterms:subject'));
    }

    /**
     * Fix with fix_data_types disabled does not convert types.
     */
    public function testFixWithoutDataTypeFlagDoesNotConvert(): void
    {
        $template = $this->createTemplate('DT NoFlag', [], [
            'dcterms:title' => [
                'data_type' => ['literal'],
            ],
            'dcterms:source' => [
                'data_type' => ['literal'],
            ],
        ]);

        $item = $this->createItem([
            'dcterms:title' => [
                ['type' => 'literal', '@value' => 'Test'],
            ],
            'dcterms:source' => [
                ['type' => 'uri', '@id' => 'http://example.org'],
            ],
        ]);
        $this->assignTemplateDirectly(
            $item->id(), $template->id()
        );

        // fix=true but fix_data_types not set.
        $this->dispatchApplyTemplate($template->id(), true);

        $updated = $this->reloadItem($item->id());
        $source = $updated->value('dcterms:source');
        $this->assertSame('uri', $source->type());
    }
}

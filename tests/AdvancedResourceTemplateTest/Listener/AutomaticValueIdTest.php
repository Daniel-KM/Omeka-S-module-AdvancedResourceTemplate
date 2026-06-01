<?php declare(strict_types=1);

namespace AdvancedResourceTemplateTest\Listener;

use AdvancedResourceTemplate\Listener\AutomaticValuesHandler;
use AdvancedResourceTemplateTest\AdvancedResourceTemplateTestTrait;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests resolution of the "{o:id}" placeholder in automatic values.
 *
 * On creation the id does not exist yet, so a sentinel is stored and replaced
 * by the real id in api.create.post. On update the id is known and resolved
 * inline before save, without creating a duplicate value.
 *
 * The id-only case does not require module Mapper: once "{o:id}" is replaced
 * there is no remaining pattern to resolve.
 */
class AutomaticValueIdTest extends AbstractHttpControllerTestCase
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
     * Read the dcterms:identifier literal values directly from the database.
     *
     * The automatic id value is resolved via a direct DBAL update in
     * api.create.post, so the database is the source of truth, not the
     * in-memory entity returned by the create response.
     */
    private function identifierValuesFromDb(int $itemId): array
    {
        $propertyId = $this->getEasyMeta()->propertyId('dcterms:identifier');
        return $this->getConnection()->executeQuery(
            'SELECT `value` FROM `value` WHERE `resource_id` = :rid AND `property_id` = :pid ORDER BY `id`',
            ['rid' => $itemId, 'pid' => $propertyId]
        )->fetchFirstColumn();
    }

    private function createIdTemplate(string $label): int
    {
        return $this->createTemplate($label, [], [
            'dcterms:identifier' => [
                'data_type' => ['literal'],
                'data' => [
                    'automatic_value' => 'https://example.com/entity/{o:id}',
                ],
            ],
        ])->id();
    }

    public function testIdPlaceholderResolvedOnCreate(): void
    {
        $templateId = $this->createIdTemplate('Auto o:id (create)');

        $item = $this->createItem([
            'dcterms:title' => [['type' => 'literal', '@value' => 'Auto id item']],
        ], $templateId);
        $id = $item->id();

        $this->assertSame(
            ['https://example.com/entity/' . $id],
            $this->identifierValuesFromDb($id)
        );
    }

    public function testNoSentinelRemainsAfterCreate(): void
    {
        $templateId = $this->createIdTemplate('Auto o:id (sentinel)');

        $item = $this->createItem([
            'dcterms:title' => [['type' => 'literal', '@value' => 'Auto id sentinel']],
        ], $templateId);

        $remaining = $this->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM `value` WHERE `resource_id` = :rid AND (`value` LIKE :like OR `uri` LIKE :like)',
            ['rid' => $item->id(), 'like' => '%' . AutomaticValuesHandler::ID_SENTINEL . '%']
        )->fetchOne();

        $this->assertSame(0, (int) $remaining);
    }

    public function testIdPlaceholderResolvedOnUpdateWithoutDuplicate(): void
    {
        $templateId = $this->createIdTemplate('Auto o:id (update)');
        $easyMeta = $this->getEasyMeta();

        $item = $this->createItem([
            'dcterms:title' => [['type' => 'literal', '@value' => 'Auto id update']],
        ], $templateId);
        $id = $item->id();

        // Re-save with the already resolved identifier present, like a normal
        // form re-submission: the automatic value must not be duplicated.
        $this->api()->update('items', $id, [
            'o:resource_template' => ['o:id' => $templateId],
            'dcterms:title' => [[
                'type' => 'literal',
                'property_id' => $easyMeta->propertyId('dcterms:title'),
                '@value' => 'Auto id update v2',
            ]],
            'dcterms:identifier' => [[
                'type' => 'literal',
                'property_id' => $easyMeta->propertyId('dcterms:identifier'),
                '@value' => 'https://example.com/entity/' . $id,
            ]],
        ]);

        $this->assertSame(
            ['https://example.com/entity/' . $id],
            $this->identifierValuesFromDb($id)
        );
    }

    private function identifierTemplate(string $label, string $automaticValue): int
    {
        return $this->createTemplate($label, [], [
            'dcterms:identifier' => [
                'data_type' => ['literal'],
                'data' => ['automatic_value' => $automaticValue],
            ],
        ])->id();
    }

    public function testCreatedPlaceholderResolvedOnCreate(): void
    {
        $templateId = $this->identifierTemplate('Auto o:created', 'created-{o:created}');

        $item = $this->createItem([
            'dcterms:title' => [['type' => 'literal', '@value' => 'Created item']],
        ], $templateId);

        $this->assertSame(
            ['created-' . $item->created()->format('c')],
            $this->identifierValuesFromDb($item->id())
        );
    }

    public function testModifiedPlaceholderResolvedOnCreate(): void
    {
        $templateId = $this->identifierTemplate('Auto o:modified', 'modified-{o:modified}');

        $item = $this->createItem([
            'dcterms:title' => [['type' => 'literal', '@value' => 'Modified item']],
        ], $templateId);

        $values = $this->identifierValuesFromDb($item->id());
        $this->assertCount(1, $values);
        $this->assertMatchesRegularExpression(
            '/^modified-\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',
            $values[0]
        );
    }

    public function testCreatedPlaceholderNotDuplicatedOnUpdate(): void
    {
        $templateId = $this->identifierTemplate('Auto o:created (update)', 'created-{o:created}');
        $easyMeta = $this->getEasyMeta();

        $item = $this->createItem([
            'dcterms:title' => [['type' => 'literal', '@value' => 'Created update']],
        ], $templateId);
        $id = $item->id();
        $resolved = 'created-' . $item->created()->format('c');

        // Re-save re-submitting the resolved identifier: created is stable and
        // resolved inline on update, so it must not be duplicated.
        $this->api()->update('items', $id, [
            'o:resource_template' => ['o:id' => $templateId],
            'dcterms:title' => [[
                'type' => 'literal',
                'property_id' => $easyMeta->propertyId('dcterms:title'),
                '@value' => 'Created update v2',
            ]],
            'dcterms:identifier' => [[
                'type' => 'literal',
                'property_id' => $easyMeta->propertyId('dcterms:identifier'),
                '@value' => $resolved,
            ]],
        ]);

        $this->assertSame([$resolved], $this->identifierValuesFromDb($id));
    }
}

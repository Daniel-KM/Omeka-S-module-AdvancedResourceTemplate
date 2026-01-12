<?php declare(strict_types=1);

namespace AdvancedResourceTemplate\Api\Adapter;

/**
 * Resource template adapter with custom representation class.
 *
 * Most logic has been moved to event listeners in Module.php:
 * - buildQuery() filter by resource type → api.search.query event
 * - hydrate() custom data (o:data) → api.hydrate.post event
 *
 * Only getRepresentationClass() override remains necessary because
 * there is no core event to change the representation class.
 */
class ResourceTemplateAdapter extends \Omeka\Api\Adapter\ResourceTemplateAdapter
{
    public function getRepresentationClass()
    {
        return \AdvancedResourceTemplate\Api\Representation\ResourceTemplateRepresentation::class;
    }
}

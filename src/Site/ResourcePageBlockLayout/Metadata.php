<?php declare(strict_types=1);

namespace AdvancedResourceTemplate\Site\ResourcePageBlockLayout;

use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Site\ResourcePageBlockLayout\ResourcePageBlockLayoutInterface;

/**
 * Display selected metadata of the resource.
 */
class Metadata implements ResourcePageBlockLayoutInterface
{
    public function getLabel() : string
    {
        return 'Advanced Resource Template: Metadata'; // @translate
    }

    public function getCompatibleResourceNames() : array
    {
        return [
            'items',
            'media',
            'item_sets',
        ];
    }

    public function render(PhpRenderer $view, AbstractResourceEntityRepresentation $resource): string
    {
        $plugins = $view->getHelperPluginManager();
        $siteSetting = $plugins->get('siteSetting');

        $metadataByGroup = $siteSetting('advancedresourcetemplate_block_metadata_fields');
        if (!$metadataByGroup) {
            $view->logger()->warn(
                'The block Metadata is appended for {resource_type}, but no metadata are configured.', // @translate
                ['resource_type' => $resource->resourceName()]
            );
            return '';
        }

        return $view->partial('common/resource-page-block-layout/metadata', [
            'resource' => $resource,
            'metadataByGroup' => $metadataByGroup,
        ]);
    }
}

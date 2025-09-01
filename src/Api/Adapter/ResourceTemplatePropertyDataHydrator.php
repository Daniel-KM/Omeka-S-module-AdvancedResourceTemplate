<?php declare(strict_types=1);

namespace AdvancedResourceTemplate\Api\Adapter;

use AdvancedResourceTemplate\Entity\ResourceTemplatePropertyData;
use Omeka\Api\Adapter\ResourceTemplateAdapter;
use Omeka\Api\Request;
use Omeka\Entity\ResourceTemplate;

class ResourceTemplatePropertyDataHydrator
{
    /**
     * Associative array by property id.
     *
     * @var \Omeka\Entity\ResourceTemplateProperty[]
     */
    protected $resourceTemplateProperties;

    /**
     * Hydrate data of a resource template property in a request.
     *
     * @param Request $request
     * @param ResourceTemplate $entity
     * @param ResourceTemplateAdapter $adapter
     */
    public function hydrate(Request $request, ResourceTemplate $entity, ResourceTemplateAdapter $adapter): void
    {
        if ($this->resourceTemplateProperties === null) {
            // To avoid a flush and issues with remove/persist, get templates
            // properties by property, that are unique.
            $list = [];
            foreach ($entity->getResourceTemplateProperties()->toArray() as $rtp) {
                $list[$rtp->getProperty()->getId()] = $rtp;
            }
            $this->resourceTemplateProperties = $list;
            unset($list);
        }

        $entityManager = $adapter->getEntityManager();
        $rtpDataRepository = $entityManager
            ->getRepository(ResourceTemplatePropertyData::class);

        // For simplicity, re-use existing template properties.
        $id = $entity->getId();
        $existings = $id ? $rtpDataRepository->findBy(['resourceTemplate' => $entity]) : [];

        // See \Omeka\Api\Adapter\ResourceTemplateAdapter
        if (count($this->resourceTemplateProperties)) {
            $data = $request->getContent();
            foreach ($data['o:resource_template_property'] as $resTemPropData) {
                // Skip when no property ID.
                if (empty($resTemPropData['o:property']['o:id'])) {
                    continue;
                }
                foreach ($resTemPropData['o:data'] ?? [] as $rtpSpecificData) {
                    // Skip empty data.
                    if (empty($rtpSpecificData)) {
                        continue;
                    }
                    $rtpData = count($existings) ? array_shift($existings) : new ResourceTemplatePropertyData();
                    $rtpData
                        ->setResourceTemplateProperty($this->resourceTemplateProperties[$resTemPropData['o:property']['o:id']])
                        ->setData($rtpSpecificData);
                    $entityManager->persist($rtpData);
                }
            }
        }

        // Remove remaining template properties.
        foreach ($existings as $rtpData) {
            $entityManager->remove($rtpData);
        }
    }

    public function setResourceTemplateProperties(array $resourceTemplateProperties)
    {
        $this->resourceTemplateProperties = $resourceTemplateProperties;
        return $this;
    }
}

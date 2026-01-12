<?php declare(strict_types=1);

namespace AdvancedResourceTemplateTest;

use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ResourceTemplateRepresentation;

/**
 * Shared test helpers for AdvancedResourceTemplate module tests.
 */
trait AdvancedResourceTemplateTestTrait
{
    /**
     * @var ServiceLocatorInterface
     */
    protected $services;

    /**
     * @var array List of created resource IDs for cleanup.
     */
    protected $createdResources = [];

    /**
     * @var array List of created template IDs for cleanup.
     */
    protected $createdTemplates = [];

    /**
     * Get the API manager.
     */
    protected function api(): ApiManager
    {
        return $this->getServiceLocator()->get('Omeka\ApiManager');
    }

    /**
     * Get the service locator.
     */
    protected function getServiceLocator(): ServiceLocatorInterface
    {
        if ($this->services === null) {
            $this->services = $this->getApplication()->getServiceManager();
        }
        return $this->services;
    }

    /**
     * Get the entity manager.
     */
    protected function getEntityManager()
    {
        return $this->getServiceLocator()->get('Omeka\EntityManager');
    }

    /**
     * Get the database connection.
     */
    protected function getConnection()
    {
        return $this->getServiceLocator()->get('Omeka\Connection');
    }

    /**
     * Login as admin user.
     */
    protected function loginAdmin(): void
    {
        $auth = $this->getServiceLocator()->get('Omeka\AuthenticationService');
        $adapter = $auth->getAdapter();
        $adapter->setIdentity('admin@example.com');
        $adapter->setCredential('root');
        $auth->authenticate();
    }

    /**
     * Logout current user.
     */
    protected function logout(): void
    {
        $auth = $this->getServiceLocator()->get('Omeka\AuthenticationService');
        $auth->clearIdentity();
    }

    /**
     * Get EasyMeta service.
     */
    protected function getEasyMeta()
    {
        return $this->getServiceLocator()->get('Common\EasyMeta');
    }

    /**
     * Get the ResourceOnSave listener from ServiceManager.
     */
    protected function getResourceOnSave()
    {
        return $this->getServiceLocator()->get(\AdvancedResourceTemplate\Listener\ResourceOnSave::class);
    }

    /**
     * Get the ResourceValidator.
     */
    protected function getResourceValidator()
    {
        $services = $this->getServiceLocator();
        $connection = $services->get('Omeka\Connection');
        $logger = $services->get('Omeka\Logger');
        return new \AdvancedResourceTemplate\Listener\ResourceValidator($connection, $logger);
    }

    /**
     * Get the AutomaticValuesHandler.
     */
    protected function getAutomaticValuesHandler()
    {
        $services = $this->getServiceLocator();
        return new \AdvancedResourceTemplate\Listener\AutomaticValuesHandler(
            $services->get('Omeka\ApiManager'),
            $services->get('Common\EasyMeta'),
            $services->get('Omeka\Connection'),
            $services
        );
    }

    /**
     * Create a test resource template with optional data.
     *
     * @param string $label Template label.
     * @param array $data Template data (o:data).
     * @param array $properties Array of property configurations.
     * @return ResourceTemplateRepresentation
     */
    protected function createTemplate(
        string $label,
        array $data = [],
        array $properties = []
    ): ResourceTemplateRepresentation {
        $easyMeta = $this->getEasyMeta();

        $templateData = [
            'o:label' => $label,
            'o:data' => $data,
            'o:resource_template_property' => [],
        ];

        foreach ($properties as $term => $config) {
            $propertyId = $easyMeta->propertyId($term);
            if (!$propertyId) {
                continue;
            }

            // The o:data must be an array of data sets (each set is an array).
            $propertyDataSets = [];
            if (!empty($config['data'])) {
                // Wrap single data config in an array if not already nested.
                $propertyDataSets = [$config['data']];
            }

            $rtpData = [
                'o:property' => ['o:id' => $propertyId],
                'o:alternate_label' => $config['alternate_label'] ?? null,
                'o:alternate_comment' => $config['alternate_comment'] ?? null,
                'o:is_required' => $config['is_required'] ?? false,
                'o:is_private' => $config['is_private'] ?? false,
                'o:data_type' => $config['data_type'] ?? ['literal'],
                'o:data' => $propertyDataSets,
            ];

            $templateData['o:resource_template_property'][] = $rtpData;
        }

        $response = $this->api()->create('resource_templates', $templateData);
        $template = $response->getContent();
        $this->createdTemplates[] = $template->id();

        return $template;
    }

    /**
     * Create a test item.
     *
     * @param array $data Item data with property terms as keys.
     * @param int|null $templateId Resource template ID.
     * @return ItemRepresentation
     */
    protected function createItem(array $data = [], ?int $templateId = null): ItemRepresentation
    {
        $easyMeta = $this->getEasyMeta();
        $itemData = [];

        if ($templateId) {
            $itemData['o:resource_template'] = ['o:id' => $templateId];
        }

        // Set default title if not provided.
        if (!isset($data['dcterms:title'])) {
            $data['dcterms:title'] = [['type' => 'literal', '@value' => 'Test Item']];
        }

        foreach ($data as $term => $values) {
            if (strpos($term, ':') === false) {
                $itemData[$term] = $values;
                continue;
            }

            $propertyId = $easyMeta->propertyId($term);
            if (!$propertyId) {
                continue;
            }

            $itemData[$term] = [];
            foreach ($values as $value) {
                $valueData = [
                    'type' => $value['type'] ?? 'literal',
                    'property_id' => $propertyId,
                ];
                if (isset($value['@value'])) {
                    $valueData['@value'] = $value['@value'];
                }
                if (isset($value['@id'])) {
                    $valueData['@id'] = $value['@id'];
                }
                if (isset($value['value_resource_id'])) {
                    $valueData['value_resource_id'] = $value['value_resource_id'];
                }
                $itemData[$term][] = $valueData;
            }
        }

        $response = $this->api()->create('items', $itemData);
        $item = $response->getContent();
        $this->createdResources[] = ['type' => 'items', 'id' => $item->id()];

        return $item;
    }

    /**
     * Create a test item set.
     *
     * @param array $data Item set data.
     * @param int|null $templateId Resource template ID.
     * @return \Omeka\Api\Representation\ItemSetRepresentation
     */
    protected function createItemSet(array $data = [], ?int $templateId = null)
    {
        $easyMeta = $this->getEasyMeta();
        $itemSetData = [];

        if ($templateId) {
            $itemSetData['o:resource_template'] = ['o:id' => $templateId];
        }

        if (!isset($data['dcterms:title'])) {
            $data['dcterms:title'] = [['type' => 'literal', '@value' => 'Test Item Set']];
        }

        foreach ($data as $term => $values) {
            if (strpos($term, ':') === false) {
                $itemSetData[$term] = $values;
                continue;
            }

            $propertyId = $easyMeta->propertyId($term);
            if (!$propertyId) {
                continue;
            }

            $itemSetData[$term] = [];
            foreach ($values as $value) {
                $valueData = [
                    'type' => $value['type'] ?? 'literal',
                    'property_id' => $propertyId,
                ];
                if (isset($value['@value'])) {
                    $valueData['@value'] = $value['@value'];
                }
                $itemSetData[$term][] = $valueData;
            }
        }

        $response = $this->api()->create('item_sets', $itemSetData);
        $itemSet = $response->getContent();
        $this->createdResources[] = ['type' => 'item_sets', 'id' => $itemSet->id()];

        return $itemSet;
    }

    /**
     * Clean up created resources after test.
     */
    protected function cleanupResources(): void
    {
        // Delete created items/item sets first.
        foreach ($this->createdResources as $resource) {
            try {
                $this->api()->delete($resource['type'], $resource['id']);
            } catch (\Exception $e) {
                // Ignore errors during cleanup.
            }
        }
        $this->createdResources = [];

        // Delete created templates.
        foreach ($this->createdTemplates as $templateId) {
            try {
                $this->api()->delete('resource_templates', $templateId);
            } catch (\Exception $e) {
                // Ignore errors during cleanup.
            }
        }
        $this->createdTemplates = [];
    }
}

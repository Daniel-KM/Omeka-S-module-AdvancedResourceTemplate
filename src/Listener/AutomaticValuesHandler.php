<?php declare(strict_types=1);

namespace AdvancedResourceTemplate\Listener;

use AdvancedResourceTemplate\Api\Representation\ResourceTemplatePropertyDataRepresentation;
use AdvancedResourceTemplate\Api\Representation\ResourceTemplateRepresentation;
use Common\Stdlib\EasyMeta;
use Doctrine\DBAL\Connection;
use Exception;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Api\Manager as ApiManager;

/**
 * Handles automatic value generation for resources.
 */
class AutomaticValuesHandler
{
    /**
     * @var \Omeka\Api\Manager
     */
    protected $api;

    /**
     * @var \Common\Stdlib\EasyMeta
     */
    protected $easyMeta;

    /**
     * @var \Doctrine\DBAL\Connection
     */
    protected $connection;

    /**
     * @var \Laminas\ServiceManager\ServiceLocatorInterface
     */
    protected $services;

    public function __construct(
        ApiManager $api,
        EasyMeta $easyMeta,
        Connection $connection,
        ServiceLocatorInterface $services
    ) {
        $this->api = $api;
        $this->easyMeta = $easyMeta;
        $this->connection = $connection;
        $this->services = $services;
    }

    /**
     * Append automatic item sets from template.
     */
    public function appendAutomaticItemSets(ResourceTemplateRepresentation $template, array $resource): array
    {
        $itemSets = $template->dataValue('item_sets');
        if (!$itemSets) {
            return $resource;
        }

        $itemSets = array_map('intval', $itemSets);
        $itemSets = array_combine($itemSets, $itemSets);

        $existingItemSets = empty($resource['o:item_set'])
            ? []
            : array_column($resource['o:item_set'], 'o:id', 'o:id');

        $newItemSets = array_diff_key($itemSets, $existingItemSets);
        if (!count($newItemSets)) {
            return $resource;
        }

        // Use connection to avoid issue with rights.
        $sql = 'SELECT `id`, `id` FROM `item_set` WHERE `id` IN (:item_set_ids) ORDER BY `id`';
        $itemSetIds = $this->connection->executeQuery(
            $sql,
            ['item_set_ids' => $newItemSets],
            ['item_set_ids' => Connection::PARAM_INT_ARRAY]
        )->fetchAllKeyValue();

        if (!$itemSetIds) {
            return $resource;
        }

        foreach ($newItemSets as $itemSetId) {
            $resource['o:item_set'][] = ['o:id' => $itemSetId];
        }

        return $resource;
    }

    /**
     * Append automatic values from template data.
     *
     * This feature requires the module Mapper to be active.
     */
    public function appendAutomaticValuesFromTemplateData(ResourceTemplateRepresentation $template, array $resource): array
    {
        $automaticValues = trim((string) $template->dataValue('automatic_values'));
        if ($automaticValues === '') {
            return $resource;
        }

        // Check if Mapper module is available for automatic values conversion.
        if (!$this->services->has('Mapper\Mapper')) {
            // Log warning only once per request.
            static $warningLogged = false;
            if (!$warningLogged) {
                $this->services->get('Omeka\Logger')->warn(
                    'The automatic values feature requires the module Mapper. Please install it to use this feature.' // @translate
                );
                $warningLogged = true;
            }
            return $resource;
        }

        // The method is currently not available here, so use the module.
        $mod = new \AdvancedResourceTemplate\Module();
        $mod->setServiceLocator($this->services);
        $mapping = $mod->stringToAutofillers("[automatic_values]\n$automaticValues");
        if (!$mapping || !$mapping['automatic_values']['mapping']) {
            return $resource;
        }

        /** @var \Mapper\Stdlib\Mapper $mapper */
        $mapper = $this->services->get('Mapper\Mapper');

        // Convert ART mapping format to Mapper format.
        $mapperMapping = $this->convertArtMappingToMapper($mapping['automatic_values']['mapping']);

        try {
            $mapper->setMapping('automatic_values', $mapperMapping);
            $newResourceData = $mapper
                ->setSource($resource)
                ->convert();
        } catch (Exception $e) {
            $this->services->get('Omeka\Logger')->warn(
                'Error applying automatic values: {error}', // @translate
                ['error' => $e->getMessage()]
            );
            return $resource;
        }

        // Append only new data.
        foreach ($newResourceData as $propertyTerm => $newValues) {
            foreach ($newValues as $newValue) {
                if ($this->isNewValue($newValue, $resource[$propertyTerm] ?? [])) {
                    $resource[$propertyTerm][] = $newValue;
                }
            }
        }

        return $resource;
    }

    /**
     * Explode value based on separator from template property data.
     */
    public function explodeValueFromTemplatePropertyData(ResourceTemplatePropertyDataRepresentation $rtpData, array $resource): array
    {
        // Explode value requires a literal value.
        if ($rtpData->dataType() !== 'literal') {
            return $resource;
        }

        $separator = (string) $rtpData->dataValue('split_separator');
        if ($separator === '') {
            return $resource;
        }

        $propertyTerm = $rtpData->property()->term();
        if (!isset($resource[$propertyTerm])) {
            return $resource;
        }

        // Check for literal value and explode when possible.
        $result = [];
        foreach ($resource[$propertyTerm] as $value) {
            if ($value['type'] !== 'literal' || !isset($value['@value'])) {
                $result[] = $value;
                continue;
            }
            foreach (array_filter(array_map('trim', explode($separator, $value['@value'])), 'strlen') as $val) {
                $v = $value;
                $v['@value'] = $val;
                $result[] = $v;
            }
        }
        $resource[$propertyTerm] = $result;

        return $resource;
    }

    /**
     * Get automatic values from template property data.
     */
    public function automaticValuesFromTemplatePropertyData(ResourceTemplatePropertyDataRepresentation $rtpData, array $resource): array
    {
        $automaticValue = trim((string) $rtpData->dataValue('automatic_value'));
        $automaticValuesIssued = trim((string) $rtpData->dataValue('automatic_value_issued'));

        if ($automaticValue === '' && $automaticValuesIssued === '') {
            return [];
        }

        $values = [];
        $property = $rtpData->property();
        $propertyTerm = $property->term();
        $propertyId = $property->id();

        if ($automaticValue !== '') {
            $value = $this->createAutomaticPropertyValue($resource, [
                'data_types' => $rtpData->dataTypes(),
                'is_public' => !$rtpData->isPrivate(),
                'term' => $propertyTerm,
                'property_id' => $propertyId,
                'value' => $automaticValue,
            ]);
            if ($value) {
                $values[] = $value;
            }
        }

        if ($automaticValuesIssued === 'first') {
            $isPublic = !empty($resource['o:is_public']);
            $hasNoValue = empty($values) && empty($resource[$propertyTerm]);
            if ($isPublic && $hasNoValue) {
                $value = $this->createAutomaticPropertyValue($resource, [
                    'data_types' => $rtpData->dataTypes(),
                    'is_public' => !$rtpData->isPrivate(),
                    'term' => $propertyTerm,
                    'property_id' => $propertyId,
                    'value' => (new \DateTime)->format('Y-m-d'),
                ]);
                if ($value) {
                    $values[] = $value;
                }
            }
        }

        return $values;
    }

    /**
     * Order values by linked resource property.
     */
    public function orderByLinkedResourcePropertyData(ResourceTemplatePropertyDataRepresentation $rtpData, array $resource): array
    {
        $orderByLinkedResourceProperties = $rtpData->dataValue('order_by_linked_resource_properties');
        if (!$orderByLinkedResourceProperties) {
            return $resource;
        }

        $propertyTerm = $rtpData->property()->term();
        if (!isset($resource[$propertyTerm]) || count($resource[$propertyTerm]) < 2) {
            return $resource;
        }

        $api = $this->api;
        $sortByLinkedProperty = function ($a, $b) use ($api, $orderByLinkedResourceProperties): int {
            $aId = empty($a['value_resource_id']) ? 0 : (int) $a['value_resource_id'];
            $bId = empty($b['value_resource_id']) ? 0 : (int) $b['value_resource_id'];

            if (!$aId && !$bId) {
                return 0;
            } elseif (!$aId) {
                return 1;
            } elseif (!$bId) {
                return -1;
            }

            foreach ($orderByLinkedResourceProperties as $propTerm => $order) {
                $order = strtolower($order) === 'desc' ? -1 : 1;
                $aResource = $api->read('resources', $aId)->getContent();
                $bResource = $api->read('resources', $bId)->getContent();
                $aVal = (string) $aResource->value($propTerm);
                $bVal = (string) $bResource->value($propTerm);

                if (!strlen($aVal) && !strlen($bVal)) {
                    continue;
                } elseif (!strlen($aVal)) {
                    return 1 * $order;
                } elseif (!strlen($bVal)) {
                    return -1 * $order;
                } elseif ($result = strnatcasecmp($aVal, $bVal)) {
                    return $result * $order;
                }
            }
            return 0;
        };

        usort($resource[$propertyTerm], $sortByLinkedProperty);

        return $resource;
    }

    /**
     * Check if a value is new (not already in existing values).
     */
    protected function isNewValue(array $newValue, array $existingValues): bool
    {
        $dataType = $newValue['type'];
        $mainType = $this->easyMeta->dataTypeMain($dataType);

        switch ($mainType) {
            case 'resource':
                $check = [
                    'type' => $dataType,
                    'value_resource_id' => (int) $newValue['value_resource_id'],
                ];
                break;
            case 'uri':
                $check = array_intersect_key($newValue, ['type' => null, '@id' => null]);
                break;
            case 'literal':
            default:
                $check = array_intersect_key($newValue, ['type' => null, '@value' => null]);
                break;
        }
        ksort($check);

        foreach ($existingValues as $value) {
            $checkValue = array_intersect_key($value, $check);
            if (isset($checkValue['value_resource_id'])) {
                $checkValue['value_resource_id'] = (int) $checkValue['value_resource_id'];
            }
            ksort($checkValue);
            if ($check === $checkValue) {
                return false;
            }
        }

        return true;
    }

    /**
     * Create an automatic property value.
     *
     * This feature requires the module Mapper or artMapper plugin for pattern
     * transformation. If not available, the feature is disabled.
     */
    protected function createAutomaticPropertyValue(array $resource, ?array $map): ?array
    {
        if (empty($map) || empty($map['property_id'])) {
            return null;
        }

        $propertyTerm = $map['term'];
        $propertyId = $map['property_id'];
        $automaticValue = $map['value'];
        $dataTypes = $map['data_types'];
        $isPublic = $map['is_public'] ?? true;
        $dataType = count($dataTypes) ? reset($dataTypes) : 'literal';

        $plugins = $this->services->get('ControllerPluginManager');
        $fieldNameToProperty = $plugins->get('fieldNameToProperty');

        // Pattern transformation requires the Mapper module.
        // This feature is temporarily disabled until full Mapper integration.
        // TODO: Implement Mapper-based pattern transformation.
        if (!$this->services->has('Mapper\Mapper')) {
            // Log warning only once per request.
            static $mapperWarningLogged = false;
            if (!$mapperWarningLogged) {
                $this->services->get('Omeka\Logger')->warn(
                    'Automatic property value patterns require the module Mapper. Please install it to use this feature.' // @translate
                );
                $mapperWarningLogged = true;
            }
            return null;
        }

        // TODO: Implement proper Mapper integration for pattern transformation.
        // For now, this feature is disabled.
        return null;

        $automaticValueArray = json_decode($automaticValue, true);

        if (is_array($automaticValueArray)) {
            return $this->createAutomaticPropertyValueFromArray(
                $resource, $automaticValueArray, $propertyTerm, $propertyId, $dataType, $dataTypes, $isPublic, $fieldNameToProperty, $mapper
            );
        }

        return $this->createAutomaticPropertyValueFromString(
            $resource, $automaticValue, $propertyTerm, $propertyId, $dataType, $isPublic, $fieldNameToProperty, $mapper
        );
    }

    /**
     * Create automatic value from array definition.
     */
    protected function createAutomaticPropertyValueFromArray(
        array $resource,
        array $automaticValueArray,
        string $propertyTerm,
        int $propertyId,
        string $dataType,
        array $dataTypes,
        bool $isPublic,
        $fieldNameToProperty,
        $mapper
    ): ?array {
        if (empty($automaticValueArray['type'])) {
            $automaticValueArray['type'] = $dataType;
        } else {
            $dataTypeManager = $this->services->get('Omeka\DataTypeManager');
            if (!$dataTypeManager->has($automaticValueArray['type'])) {
                return null;
            }
            if ($dataTypes && !in_array($automaticValueArray['type'], $dataTypes)) {
                return null;
            }
        }

        $dataType = $automaticValueArray['type'];
        $mainType = $this->easyMeta->dataTypeMain($dataType);

        switch ($mainType) {
            case 'resource':
                if (empty($automaticValueArray['value_resource_id'])) {
                    return null;
                }
                $vrid = $automaticValueArray['value_resource_id'];
                $to = "$propertyTerm ^^$dataType ~ $vrid";
                $to = $fieldNameToProperty($to);
                if (!$to) {
                    return null;
                }
                $automaticValueArray['value_resource_id'] = (int) $mapper
                    ->setMapping([])
                    ->setIsSimpleExtract(false)
                    ->setIsInternalSource(true)
                    ->extractValueOnly($resource, ['from' => '~', 'to' => $to]);
                try {
                    $this->api->read('resources', ['id' => $vrid], ['initialize' => false, 'finalize' => false]);
                } catch (Exception $e) {
                    return null;
                }
                $check = array_intersect_key($automaticValueArray, ['type' => null, 'value_resource_id' => null]);
                break;

            case 'uri':
                if (empty($automaticValueArray['@id'])) {
                    return null;
                }
                $uri = $automaticValueArray['@id'];
                $to = "$propertyTerm ^^$dataType ~ $uri";
                $to = $fieldNameToProperty($to);
                if (!$to) {
                    return null;
                }
                $automaticValueArray['@id'] = $mapper
                    ->setMapping([])
                    ->setIsSimpleExtract(false)
                    ->setIsInternalSource(true)
                    ->extractValueOnly($resource, ['from' => '~', 'to' => $to]);
                $check = array_intersect_key($automaticValueArray, ['type' => null, '@id' => null]);
                break;

            case 'literal':
            default:
                if (!isset($automaticValueArray['@value']) || !strlen((string) $automaticValueArray['@value'])) {
                    return null;
                }
                $val = $automaticValueArray['@value'];
                $to = "$propertyTerm ^^$dataType ~ $val";
                $to = $fieldNameToProperty($to);
                if (!$to) {
                    return null;
                }
                $automaticValueArray['@value'] = $mapper
                    ->setMapping([])
                    ->setIsSimpleExtract(false)
                    ->setIsInternalSource(true)
                    ->extractValueOnly($resource, ['from' => '~', 'to' => $to]);
                $check = array_intersect_key($automaticValueArray, ['type' => null, '@value' => null]);
                break;
        }

        // Check if the value already exists.
        if (!$this->isNewValueByCheck($check, $resource[$propertyTerm] ?? [])) {
            return null;
        }

        return ['property_id' => $propertyId] + $automaticValueArray + ['is_public' => $isPublic];
    }

    /**
     * Create automatic value from string definition.
     */
    protected function createAutomaticPropertyValueFromString(
        array $resource,
        string $automaticValue,
        string $propertyTerm,
        int $propertyId,
        string $dataType,
        bool $isPublic,
        $fieldNameToProperty,
        $mapper
    ): ?array {
        $mainType = $this->easyMeta->dataTypeMain($dataType);
        $to = "$propertyTerm ^^$dataType ~ $automaticValue";
        $to = $fieldNameToProperty($to);
        if (!$to) {
            return null;
        }

        $automaticValueTransformed = $mapper
            ->setMapping([])
            ->setIsSimpleExtract(false)
            ->setIsInternalSource(true)
            ->extractValueOnly($resource, ['from' => '~', 'to' => $to]);

        switch ($mainType) {
            case 'resource':
                $automaticValueTransformed = (int) $automaticValueTransformed;
                try {
                    $this->api->read('resources', ['id' => $automaticValueTransformed], ['initialize' => false, 'finalize' => false]);
                } catch (Exception $e) {
                    return null;
                }
                $automaticValueArray = [
                    'type' => $dataType,
                    'value_resource_id' => $automaticValueTransformed,
                ];
                break;

            case 'uri':
                $automaticValueArray = [
                    'type' => $dataType,
                    '@id' => $automaticValueTransformed,
                ];
                break;

            case 'literal':
            default:
                $automaticValueArray = [
                    'type' => $dataType,
                    '@value' => $automaticValueTransformed,
                ];
                break;
        }

        $check = $automaticValueArray;

        // Check if the value already exists.
        if (!$this->isNewValueByCheck($check, $resource[$propertyTerm] ?? [])) {
            return null;
        }

        return ['property_id' => $propertyId] + $automaticValueArray + ['is_public' => $isPublic];
    }

    /**
     * Check if value is new by comparing against existing values.
     */
    protected function isNewValueByCheck(array $check, array $existingValues): bool
    {
        $fixValue = fn ($value) => is_string($value) ? trim($value) : $value;
        $check = array_map($fixValue, $check);
        ksort($check);

        foreach ($existingValues as $value) {
            $checkValue = array_intersect_key($value, $check);
            if (isset($checkValue['value_resource_id'])) {
                $checkValue['value_resource_id'] = (int) $checkValue['value_resource_id'];
            }
            $checkValue = array_map($fixValue, $checkValue);
            ksort($checkValue);
            if ($check === $checkValue) {
                return false;
            }
        }

        return true;
    }

    /**
     * Convert ART mapping format to Mapper INI format.
     *
     * ART format: [['from' => 'source', 'to' => ['field' => 'term', ...]]]
     * Mapper INI format: string with [info] and [maps] sections
     *
     * @param array $artMapping
     * @return array Mapper-compatible configuration array
     */
    protected function convertArtMappingToMapper(array $artMapping): array
    {
        $maps = [];
        foreach ($artMapping as $rule) {
            $from = $rule['from'] ?? '';
            $to = $rule['to'] ?? [];
            if (!$from || !$to) {
                continue;
            }

            $field = $to['field'] ?? null;
            if (!$field) {
                continue;
            }

            $mapEntry = [
                'from' => $from,
                'to' => $field,
            ];

            if (!empty($to['type'])) {
                $mapEntry['datatype'] = $to['type'];
            }
            if (!empty($to['pattern'])) {
                $mapEntry['pattern'] = $to['pattern'];
            }
            if (isset($to['@language'])) {
                $mapEntry['language'] = $to['@language'];
            }
            if (isset($to['is_public'])) {
                $mapEntry['is_public'] = $to['is_public'];
            }

            $maps[] = $mapEntry;
        }

        return [
            'info' => [
                'label' => 'Automatic Values',
                'querier' => 'jsdot',
            ],
            'maps' => $maps,
        ];
    }
}

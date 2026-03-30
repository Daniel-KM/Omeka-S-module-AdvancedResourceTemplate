<?php declare(strict_types=1);

namespace AdvancedResourceTemplate\Job;

use AdvancedResourceTemplate\Api\Representation\ResourceTemplateRepresentation;
use AdvancedResourceTemplate\Listener\AutomaticValuesHandler;
use AdvancedResourceTemplate\Stdlib\ArtTrait;
use Doctrine\DBAL\Connection;
use Omeka\Job\AbstractJob;

/**
 * Background job to check and fix resources according to constraints defined in
 * a resource template (required, default, automatic, length, value count, etc.).
 *
 * In audit mode (fix=false) issues are only logged.
 * In fix mode (fix=true) correctable issues are fixed and then logged.
 */
class ApplyTemplate extends AbstractJob
{
    use ArtTrait;

    /**
     * Number of resource IDs to process per chunk.
     */
    const CHUNK_SIZE = 100;

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
     * @var \Doctrine\ORM\EntityManager
     */
    protected $entityManager;

    /**
     * @var \Laminas\Log\Logger
     */
    protected $logger;

    /**
     * @var \AdvancedResourceTemplate\Listener\AutomaticValuesHandler
     */
    protected $automaticValuesHandler;

    /**
     * @var bool
     */
    protected $fix;

    /**
     * @var bool
     */
    protected $fixDefaultValues;

    /**
     * @var bool
     */
    protected $fixAutomaticValues;

    /**
     * @var bool
     */
    protected $fixTruncate;

    /**
     * @var bool
     */
    protected $fixMaxValues;

    /**
     * @var bool
     */
    protected $fixVisibility;

    /**
     * @var bool
     */
    protected $fixExtraProperties;

    /**
     * @var bool
     */
    protected $fixDataTypes;

    /**
     * Indexed constraints by property term.
     *
     * Each entry is an array with keys: property_id, required, default_value,
     * automatic_value, max_length, min_length, max_values, min_values,
     * input_control, unique_value, data_types, is_private, rtp_data.
     *
     * @var array
     */
    protected $constraints = [];

    /**
     * All template properties indexed by term, with their allowed data types.
     * Used to detect extra properties and wrong data types on resources.
     *
     * @var array<string, array{property_id: int, data_types: string[]}>
     */
    protected $templateProperties = [];

    /**
     * Track which template property terms are actually used across all
     * resources (for the unused report).
     *
     * @var array<string, bool>
     */
    protected $usedProperties = [];

    /**
     * Extra property terms found on resources, with their property ID.
     * Used to build browse links.
     *
     * @var array<string, int>
     */
    protected $extraPropertyIds = [];

    /**
     * Missing required property terms, with their property ID.
     * Used to build browse links.
     *
     * @var array<string, int>
     */
    protected $missingRequiredIds = [];

    /**
     * Resource types that had resources to process.
     *
     * @var string[]
     */
    protected $processedResourceTypes = [];

    /**
     * Counters for the summary log.
     *
     * @var array
     */
    protected $totals = [
        'resources' => 0,
        'issues' => 0,
        'fixed' => 0,
        'skipped' => 0,
    ];

    public function perform(): void
    {
        $services = $this->getServiceLocator();

        $this->api = $services->get('Omeka\ApiManager');
        $this->logger = $services->get('Omeka\Logger');
        $this->easyMeta = $services->get('Common\EasyMeta');
        $this->connection = $services->get('Omeka\Connection');
        $this->entityManager = $services->get('Omeka\EntityManager');

        $referenceIdProcessor = new \Laminas\Log\Processor\ReferenceId();
        $referenceIdProcessor->setReferenceId(
            'art/apply-template/job_' . $this->job->getId()
        );
        $this->logger->addProcessor($referenceIdProcessor);

        $templateId = (int) $this->getArg('template_id');
        if (!$templateId) {
            $this->logger->err(
                'No template id provided.' // @translate
            );
            return;
        }

        $this->fix = (bool) $this->getArg('fix', false);
        $this->fixDefaultValues = (bool) $this->getArg('fix_default_values', false);
        $this->fixAutomaticValues = (bool) $this->getArg('fix_automatic_values', false);
        $this->fixTruncate = (bool) $this->getArg('fix_truncate', false);
        $this->fixMaxValues = (bool) $this->getArg('fix_max_values', false);
        $this->fixVisibility = (bool) $this->getArg('fix_visibility', false);
        $this->fixExtraProperties = (bool) $this->getArg('fix_extra_properties', false);
        $this->fixDataTypes = (bool) $this->getArg('fix_data_types', false);

        /** @var \AdvancedResourceTemplate\Api\Representation\ResourceTemplateRepresentation $template */
        try {
            $template = $this->api
                ->read('resource_templates', ['id' => $templateId])
                ->getContent();
        } catch (\Throwable $e) {
            $this->logger->err(
                'Template #{template_id} not found.', // @translate
                ['template_id' => $templateId]
            );
            return;
        }

        $this->initAutomaticValuesHandler($services);
        $this->indexConstraints($template);
        $this->indexTemplateProperties($template);

        $mode = $this->fix ? 'fix' : 'audit';
        $this->logger->notice(
            'Applying template "{label}" (mode: {mode}).', // @translate
            ['label' => $template->label(), 'mode' => $mode]
        );

        $this->processResourceType(
            'items', $template, $templateId
        );
        $this->processResourceType(
            'item_sets', $template, $templateId
        );
        $this->processResourceType(
            'media', $template, $templateId
        );

        $this->reportUnusedProperties($template);
        $this->logBrowseLinks($templateId);

        $this->logger->notice(
            $this->fix
                ? 'Template "{label}" applied: {resources} resources checked, {issues} issues found, {fixed} fixed, {skipped} not fixable.' // @translate
                : 'Template "{label}" audited: {resources} resources checked, {issues} issues found.', // @translate
            [
                'label' => $template->label(),
                'resources' => $this->totals['resources'],
                'issues' => $this->totals['issues'],
                'fixed' => $this->totals['fixed'],
                'skipped' => $this->totals['skipped'],
            ]
        );
    }

    /**
     * Index all constrained properties from the template into constraints,
     * keyed by property term.
     */
    protected function indexConstraints(
        ResourceTemplateRepresentation $template
    ): void {
        foreach ($template->resourceTemplateProperties() as $rtp) {
            $property = $rtp->property();
            $propertyTerm = $property->term();
            $propertyId = $property->id();

            foreach ($rtp->data() as $rtpData) {
                $required = $rtpData->isRequired();
                $defaultValue = $rtpData->dataValue('default_value');
                $automaticValue = $rtpData->dataValue('automatic_value');
                $maxLength = (int) $rtpData->dataValue('max_length');
                $minLength = (int) $rtpData->dataValue('min_length');
                $maxValues = (int) $rtpData->dataValue('max_values');
                $minValues = (int) $rtpData->dataValue('min_values');
                $inputControl = (string) $rtpData->dataValue('input_control');
                $uniqueValue = (bool) $rtpData->dataValue('unique_value');
                $isPrivate = $rtpData->isPrivate();

                // Keep only properties that have at least one constraint, to
                // avoid unnecessary processing.
                if (!$required
                    && !$defaultValue
                    && !$automaticValue
                    && !$maxLength
                    && !$minLength
                    && !$maxValues
                    && !$minValues
                    && !strlen($inputControl)
                    && !$uniqueValue
                    && !$isPrivate
                ) {
                    continue;
                }

                $this->constraints[$propertyTerm][] = [
                    'property_id' => $propertyId,
                    'required' => $required,
                    'default_value' => $defaultValue,
                    'automatic_value' => $automaticValue,
                    'max_length' => $maxLength,
                    'min_length' => $minLength,
                    'max_values' => $maxValues,
                    'min_values' => $minValues,
                    'input_control' => $inputControl,
                    'unique_value' => $uniqueValue,
                    'data_types' => $rtpData->dataTypes(),
                    'is_private' => $rtpData->isPrivate(),
                    'rtp_data' => $rtpData,
                ];
            }
        }
    }

    /**
     * Index all template properties with their allowed data types.
     * Unlike indexConstraints(), this includes every property regardless of
     * whether it has constraints.
     */
    protected function indexTemplateProperties(
        ResourceTemplateRepresentation $template
    ): void {
        foreach ($template->resourceTemplateProperties() as $rtp) {
            $property = $rtp->property();
            $term = $property->term();
            $dataTypes = [];
            foreach ($rtp->data() as $rtpData) {
                foreach ($rtpData->dataTypes() as $dt) {
                    $dataTypes[] = $dt;
                }
            }
            $this->templateProperties[$term] = [
                'property_id' => $property->id(),
                'data_types' => array_unique($dataTypes),
            ];
            $this->usedProperties[$term] = false;
        }
    }

    /**
     * Initialize the AutomaticValuesHandler from services.
     */
    protected function initAutomaticValuesHandler(
        \Laminas\ServiceManager\ServiceLocatorInterface $services
    ): void {
        $this->automaticValuesHandler = new AutomaticValuesHandler(
            $this->api,
            $this->easyMeta,
            $this->connection,
            $services
        );
    }

    /**
     * Process all resources of a given type that use the template.
     */
    protected function processResourceType(
        string $resourceType,
        ResourceTemplateRepresentation $template,
        int $templateId
    ): void {
        $entityClass = $this->resourceTypeToEntityClass($resourceType);
        if (!$entityClass) {
            return;
        }

        $sql = <<<'SQL'
            SELECT r.id
            FROM resource r
            WHERE r.resource_type = :resource_type
                AND r.resource_template_id = :template_id
            ORDER BY r.id ASC
            SQL;

        $resourceIds = $this->connection->executeQuery(
            $sql,
            [
                'resource_type' => $entityClass,
                'template_id' => $templateId,
            ]
        )->fetchFirstColumn();

        if (!count($resourceIds)) {
            return;
        }

        $this->processedResourceTypes[] = $resourceType;

        $this->logger->info(
            'Processing {count} {resource_type} with template.', // @translate
            [
                'count' => count($resourceIds),
                'resource_type' => $resourceType,
            ]
        );

        foreach (array_chunk($resourceIds, self::CHUNK_SIZE) as $chunk) {
            if ($this->shouldStop()) {
                $this->logger->warn(
                    'Job stopped by user.' // @translate
                );
                return;
            }

            foreach ($chunk as $resourceId) {
                $this->processResource(
                    $resourceType, (int) $resourceId, $template
                );
            }

            // Avoid memory issues on large batches.
            $this->entityManager->clear();
        }
    }

    /**
     * Check (and optionally fix) a single resource against indexed constraints.
     */
    protected function processResource(
        string $resourceType,
        int $resourceId,
        ResourceTemplateRepresentation $template
    ): void {
        try {
            $resource = $this->api
                ->read($resourceType, $resourceId)
                ->getContent();
        } catch (\Throwable $e) {
            $this->logger->warn(
                'Resource #{resource_id} cannot be read: {error}', // @translate
                [
                    'resource_id' => $resourceId,
                    'error' => $e->getMessage(),
                ]
            );
            return;
        }

        ++$this->totals['resources'];

        // Serialize the representation to an editable array.
        $data = json_decode(json_encode($resource), true);

        $modified = false;

        foreach ($this->constraints as $term => $constraintList) {
            foreach ($constraintList as $constraint) {
                $result = $this->checkProperty(
                    $data, $resource, $resourceId, $term, $constraint
                );
                if ($result !== null) {
                    $data = $result;
                    $modified = true;
                }
            }
        }

        // Check extra properties, wrong data types, and track used template
        // properties.
        $result = $this->checkExtraProperties(
            $data, $resourceId
        );
        if ($result !== null) {
            $data = $result;
            $modified = true;
        }

        if ($modified && $this->fix) {
            try {
                $this->api->update(
                    $resourceType,
                    $resourceId,
                    $data,
                    [],
                    ['isPartial' => true]
                );
            } catch (\Throwable $e) {
                $this->logger->err(
                    'Resource #{resource_id}: update failed: {error}', // @translate
                    [
                        'resource_id' => $resourceId,
                        'error' => $e->getMessage(),
                    ]
                );
            }
        }
    }

    /**
     * Check a single property constraint on a resource. Returns the modified
     * data array when a fix is applied, or null if no modification was made.
     */
    protected function checkProperty(
        array $data,
        $resource,
        int $resourceId,
        string $term,
        array $constraint
    ): ?array {
        $modified = false;
        $values = $data[$term] ?? [];

        // --- Required check.
        if ($constraint['required'] && !count($values)) {
            ++$this->totals['issues'];
            $this->missingRequiredIds[$term] = $constraint['property_id'];
            if ($this->fix
                && $this->fixDefaultValues
                && $constraint['default_value']
            ) {
                $newValue = $this->buildDefaultValue(
                    $constraint, $term
                );
                if ($newValue) {
                    $data[$term][] = $newValue;
                    $values = $data[$term];
                    $modified = true;
                    ++$this->totals['fixed'];
                    $this->logger->info(
                        'Resource #{resource_id}: {term}: added default value for required property.', // @translate
                        [
                            'resource_id' => $resourceId,
                            'term' => $term,
                        ]
                    );
                }
            } elseif ($constraint['default_value']) {
                ++$this->totals['skipped'];
                $this->logger->info(
                    'Resource #{resource_id}: {term}: required but empty (fixable with default value).', // @translate
                    [
                        'resource_id' => $resourceId,
                        'term' => $term,
                    ]
                );
            } else {
                ++$this->totals['skipped'];
                $this->logger->info(
                    'Resource #{resource_id}: {term}: required but empty (no default value).', // @translate
                    [
                        'resource_id' => $resourceId,
                        'term' => $term,
                    ]
                );
            }
        }

        // --- Default value: add when property is empty, even if not required.
        if (!$constraint['required']
            && !count($values)
            && $constraint['default_value']
        ) {
            ++$this->totals['issues'];
            if ($this->fix && $this->fixDefaultValues) {
                $newValue = $this->buildDefaultValue(
                    $constraint, $term
                );
                if ($newValue) {
                    $data[$term][] = $newValue;
                    $values = $data[$term];
                    $modified = true;
                    ++$this->totals['fixed'];
                    $this->logger->info(
                        'Resource #{resource_id}: {term}: added default value.', // @translate
                        [
                            'resource_id' => $resourceId,
                            'term' => $term,
                        ]
                    );
                }
            } else {
                ++$this->totals['skipped'];
                $this->logger->info(
                    'Resource #{resource_id}: {term}: empty, has default value.', // @translate
                    [
                        'resource_id' => $resourceId,
                        'term' => $term,
                    ]
                );
            }
        }

        // --- Automatic value: recalculate.
        if ($constraint['automatic_value']
            && $this->fix
            && $this->fixAutomaticValues
        ) {
            /** @var \AdvancedResourceTemplate\Api\Representation\ResourceTemplatePropertyDataRepresentation $rtpData */
            $rtpData = $constraint['rtp_data'];
            $autoValues = $this->automaticValuesHandler
                ->automaticValuesFromTemplatePropertyData($rtpData, $data);
            foreach ($autoValues as $autoValue) {
                if ($this->isNewValue($autoValue, $values)) {
                    $data[$term][] = $autoValue;
                    $modified = true;
                    ++$this->totals['issues'];
                    ++$this->totals['fixed'];
                    $this->logger->info(
                        'Resource #{resource_id}: {term}: added automatic value.', // @translate
                        [
                            'resource_id' => $resourceId,
                            'term' => $term,
                        ]
                    );
                }
            }
            $values = $data[$term] ?? [];
        }

        // --- Max length: truncate literals.
        if ($constraint['max_length'] && count($values)) {
            $maxLen = $constraint['max_length'];
            foreach ($values as $k => $value) {
                if (!$this->isLiteral($value)) {
                    continue;
                }
                $val = $value['@value'] ?? '';
                if (mb_strlen($val) > $maxLen) {
                    ++$this->totals['issues'];
                    if ($this->fix && $this->fixTruncate) {
                        $data[$term][$k]['@value'] = mb_substr(
                            $val, 0, $maxLen
                        );
                        $modified = true;
                        ++$this->totals['fixed'];
                        $this->logger->info(
                            'Resource #{resource_id}: {term}: value truncated from {length} to {max} characters.', // @translate
                            [
                                'resource_id' => $resourceId,
                                'term' => $term,
                                'length' => mb_strlen($val),
                                'max' => $maxLen,
                            ]
                        );
                    } else {
                        ++$this->totals['skipped'];
                        $this->logger->info(
                            'Resource #{resource_id}: {term}: value exceeds max length ({length}/{max}).', // @translate
                            [
                                'resource_id' => $resourceId,
                                'term' => $term,
                                'length' => mb_strlen($val),
                                'max' => $maxLen,
                            ]
                        );
                    }
                }
            }
            $values = $data[$term] ?? [];
        }

        // --- Min length: report only (not fixable).
        if ($constraint['min_length'] && count($values)) {
            $minLen = $constraint['min_length'];
            foreach ($values as $value) {
                if (!$this->isLiteral($value)) {
                    continue;
                }
                $val = $value['@value'] ?? '';
                if (mb_strlen($val) < $minLen) {
                    ++$this->totals['issues'];
                    ++$this->totals['skipped'];
                    $this->logger->info(
                        'Resource #{resource_id}: {term}: value too short ({length}/{min}, not fixable).', // @translate
                        [
                            'resource_id' => $resourceId,
                            'term' => $term,
                            'length' => mb_strlen($val),
                            'min' => $minLen,
                        ]
                    );
                }
            }
        }

        // --- Max values: remove excess.
        if ($constraint['max_values'] && count($values) > $constraint['max_values']) {
            $maxVals = $constraint['max_values'];
            $excess = count($values) - $maxVals;
            ++$this->totals['issues'];
            if ($this->fix && $this->fixMaxValues) {
                $data[$term] = array_slice($values, 0, $maxVals);
                $modified = true;
                ++$this->totals['fixed'];
                $this->logger->info(
                    'Resource #{resource_id}: {term}: removed {count} excess values (max {max}).', // @translate
                    [
                        'resource_id' => $resourceId,
                        'term' => $term,
                        'count' => $excess,
                        'max' => $maxVals,
                    ]
                );
            } else {
                ++$this->totals['skipped'];
                $this->logger->info(
                    'Resource #{resource_id}: {term}: {count} values exceed max of {max}.', // @translate
                    [
                        'resource_id' => $resourceId,
                        'term' => $term,
                        'count' => count($values),
                        'max' => $maxVals,
                    ]
                );
            }
            $values = $data[$term] ?? [];
        }

        // --- Min values: report only (not fixable).
        if ($constraint['min_values']
            && count($values) < $constraint['min_values']
        ) {
            ++$this->totals['issues'];
            ++$this->totals['skipped'];
            $this->logger->info(
                'Resource #{resource_id}: {term}: {count} values below min of {min} (not fixable).', // @translate
                [
                    'resource_id' => $resourceId,
                    'term' => $term,
                    'count' => count($values),
                    'min' => $constraint['min_values'],
                ]
            );
        }

        // --- Input control: report only (not fixable).
        if (strlen($constraint['input_control']) && count($values)) {
            $regex = $this->buildRegex($constraint['input_control']);
            if ($regex) {
                foreach ($values as $value) {
                    if (!$this->isLiteral($value)) {
                        continue;
                    }
                    $val = $value['@value'] ?? '';
                    if (!preg_match($regex, $val)) {
                        ++$this->totals['issues'];
                        ++$this->totals['skipped'];
                        $this->logger->info(
                            'Resource #{resource_id}: {term}: value does not match pattern (not fixable).', // @translate
                            [
                                'resource_id' => $resourceId,
                                'term' => $term,
                            ]
                        );
                    }
                }
            }
        }

        // --- Visibility: fix values whose visibility differs from template
        // constraint.
        if ($constraint['is_private'] && count($values)) {
            $expectedPublic = !$constraint['is_private'];
            foreach ($values as $k => $value) {
                $isPublic = $value['is_public'] ?? true;
                if ($isPublic !== $expectedPublic) {
                    ++$this->totals['issues'];
                    if ($this->fix && $this->fixVisibility) {
                        $data[$term][$k]['is_public'] = $expectedPublic;
                        $modified = true;
                        ++$this->totals['fixed'];
                        $this->logger->info(
                            'Resource #{resource_id}: {term}: visibility changed to {visibility}.', // @translate
                            [
                                'resource_id' => $resourceId,
                                'term' => $term,
                                'visibility' => $expectedPublic ? 'public' : 'private',
                            ]
                        );
                    } else {
                        ++$this->totals['skipped'];
                        $this->logger->info(
                            'Resource #{resource_id}: {term}: value is {actual}, should be {expected}.', // @translate
                            [
                                'resource_id' => $resourceId,
                                'term' => $term,
                                'actual' => $isPublic ? 'public' : 'private',
                                'expected' => $expectedPublic ? 'public' : 'private',
                            ]
                        );
                    }
                }
            }
            $values = $data[$term] ?? [];
        }

        // --- Unique value: report only (not fixable).
        if ($constraint['unique_value'] && count($values)) {
            $this->checkUniqueness(
                $resourceId,
                $constraint['property_id'],
                $term,
                $values
            );
        }

        return $modified ? $data : null;
    }

    /**
     * Build a value array from the default_value setting.
     *
     * The default_value can be a plain string or a JSON object with keys like
     * @value, @id, value_resource_id, etc.
     */
    protected function buildDefaultValue(
        array $constraint,
        string $term
    ): ?array {
        $defaultValue = trim((string) $constraint['default_value']);
        if ($defaultValue === '') {
            return null;
        }

        $propertyId = $constraint['property_id'];
        $dataTypes = $constraint['data_types'];
        $dataType = count($dataTypes) ? reset($dataTypes) : 'literal';
        $isPublic = !$constraint['is_private'];

        $decoded = @json_decode($defaultValue, true);
        if (is_array($decoded)) {
            $value = [
                'property_id' => $propertyId,
                'type' => $decoded['type'] ?? $dataType,
                'is_public' => $isPublic,
            ];
            if (isset($decoded['@value'])) {
                $value['@value'] = $decoded['@value'];
            } elseif (isset($decoded['@id'])) {
                $value['@id'] = $decoded['@id'];
                if (isset($decoded['o:label'])) {
                    $value['o:label'] = $decoded['o:label'];
                }
            } elseif (isset($decoded['value_resource_id'])) {
                $value['value_resource_id'] = (int) $decoded['value_resource_id'];
            } elseif (isset($decoded['default'])) {
                $value['@value'] = $decoded['default'];
            }
            return $value;
        }

        // Plain string: create a literal value.
        return [
            'property_id' => $propertyId,
            'type' => $dataType,
            '@value' => $defaultValue,
            'is_public' => $isPublic,
        ];
    }

    /**
     * Build a regex from an HTML input pattern, same logic as
     * ResourceValidator::validateInputControl().
     */
    protected function buildRegex(string $inputControl): ?string
    {
        $anchors = ['/', '#', '~', '%', '`', ';', '§', 'µ'];
        foreach ($anchors as $anchor) {
            if (mb_strpos($inputControl, $anchor) === false) {
                $regex = $anchor . '^(?:' . $inputControl . ')$'
                    . $anchor . 'u';
                if (@preg_match($regex, '') !== false) {
                    return $regex;
                }
            }
        }
        return null;
    }

    /**
     * Check uniqueness of values for a property against the database.
     */
    protected function checkUniqueness(
        int $resourceId,
        int $propertyId,
        string $term,
        array $values
    ): void {
        $bind = [
            'resource_id' => $resourceId,
            'property_id' => $propertyId,
        ];
        $types = [
            'resource_id' => \Doctrine\DBAL\ParameterType::INTEGER,
            'property_id' => \Doctrine\DBAL\ParameterType::INTEGER,
        ];
        $sqlWhere = [];

        foreach ($values as $value) {
            if (!empty($value['value_resource_id'])) {
                $bind['resource'][] = (int) $value['value_resource_id'];
            } elseif (!empty($value['@id'])) {
                $bind['uri'][] = $value['@id'];
            } elseif (isset($value['@value'])) {
                $bind['literal'][] = $value['@value'];
            }
        }

        if (isset($bind['resource'])) {
            $sqlWhere[] = 'value.value_resource_id IN (:resource)';
            $types['resource'] = Connection::PARAM_INT_ARRAY;
        }
        if (isset($bind['uri'])) {
            $sqlWhere[] = 'value.uri IN (:uri)';
            $types['uri'] = Connection::PARAM_STR_ARRAY;
        }
        if (isset($bind['literal'])) {
            $sqlWhere[] = 'value.value IN (:literal)';
            $types['literal'] = Connection::PARAM_STR_ARRAY;
        }

        if (!$sqlWhere) {
            return;
        }

        $sqlWhereStr = implode(' OR ', $sqlWhere);
        $sql = <<<SQL
            SELECT value.resource_id
            FROM value
            WHERE value.resource_id != :resource_id
                AND value.property_id = :property_id
                AND ($sqlWhereStr)
            LIMIT 1
            SQL;

        $duplicate = $this->connection
            ->executeQuery($sql, $bind, $types)
            ->fetchOne();
        if ($duplicate) {
            ++$this->totals['issues'];
            ++$this->totals['skipped'];
            $this->logger->info(
                'Resource #{resource_id}: {term}: duplicate value found in resource #{duplicate_id} (not fixable).', // @translate
                [
                    'resource_id' => $resourceId,
                    'term' => $term,
                    'duplicate_id' => $duplicate,
                ]
            );
        }
    }

    /**
     * Check if a value is a literal type.
     */
    protected function isLiteral(array $value): bool
    {
        $type = $value['type'] ?? 'literal';
        $mainType = $this->easyMeta->dataTypeMain($type);
        return $mainType === 'literal' || $mainType === null;
    }

    /**
     * Check if a value is new (not already present in a list).
     *
     * Compares by type + main content key.
     */
    protected function isNewValue(array $newValue, array $existingValues): bool
    {
        $dataType = $newValue['type'] ?? 'literal';
        $mainType = $this->easyMeta->dataTypeMain($dataType);

        switch ($mainType) {
            case 'resource':
                $check = [
                    'type' => $dataType,
                    'value_resource_id' => (int) ($newValue['value_resource_id'] ?? 0),
                ];
                break;
            case 'uri':
                $check = array_intersect_key(
                    $newValue, ['type' => null, '@id' => null]
                );
                break;
            case 'literal':
            default:
                $check = array_intersect_key(
                    $newValue, ['type' => null, '@value' => null]
                );
                break;
        }
        ksort($check);

        foreach ($existingValues as $value) {
            $cmp = array_intersect_key($value, $check);
            if (isset($cmp['value_resource_id'])) {
                $cmp['value_resource_id'] = (int) $cmp['value_resource_id'];
            }
            ksort($cmp);
            if ($check === $cmp) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check resource for extra properties not in template and wrong data types.
     *
     * Mark used template properties. Returns modified data or null.
     */
    protected function checkExtraProperties(
        array $data,
        int $resourceId
    ): ?array {
        // Property terms in the serialized data are all keys that contain a
        // colon (vocabulary:localName).
        $modified = false;
        $extraTerms = [];

        foreach ($data as $term => $values) {
            if (strpos($term, ':') === false || !is_array($values)) {
                continue;
            }

            // Known template property.
            if (isset($this->templateProperties[$term])) {
                $this->usedProperties[$term] = true;
                $allowedTypes = $this->templateProperties[$term]['data_types'];
                if (!$allowedTypes) {
                    continue;
                }
                foreach ($values as $k => $value) {
                    $type = $value['type'] ?? 'literal';
                    if (in_array($type, $allowedTypes)) {
                        continue;
                    }
                    ++$this->totals['issues'];
                    if ($this->fix && $this->fixDataTypes) {
                        $converted = $this->convertDataType(
                            $value, $allowedTypes, $resourceId, $term
                        );
                        if ($converted !== null) {
                            $data[$term][$k] = $converted;
                            $modified = true;
                            ++$this->totals['fixed'];
                            continue;
                        }
                    }
                    ++$this->totals['skipped'];
                    $this->logger->info(
                        'Resource #{resource_id}: {term}: data type "{type}" is not allowed by the template (allowed: {allowed}). Use BulkEdit for manual conversion.', // @translate
                        [
                            'resource_id' => $resourceId,
                            'term' => $term,
                            'type' => $type,
                            'allowed' => implode(', ', $allowedTypes),
                        ]
                    );
                }
                continue;
            }

            // Extra property: not in the template.
            ++$this->totals['issues'];
            $extraTerms[] = $term;
            if (!isset($this->extraPropertyIds[$term])) {
                $propertyId = $values[0]['property_id'] ?? null;
                if ($propertyId) {
                    $this->extraPropertyIds[$term] = (int) $propertyId;
                }
            }
            if ($this->fix && $this->fixExtraProperties) {
                unset($data[$term]);
                $modified = true;
                ++$this->totals['fixed'];
            } else {
                ++$this->totals['skipped'];
            }
        }

        if ($extraTerms) {
            if ($this->fix && $this->fixExtraProperties) {
                $this->logger->info(
                    'Resource #{resource_id}: removed properties not in template: {terms}.', // @translate
                    [
                        'resource_id' => $resourceId,
                        'terms' => implode(', ', $extraTerms),
                    ]
                );
            } else {
                $this->logger->info(
                    'Resource #{resource_id}: properties not in template: {terms}.', // @translate
                    [
                        'resource_id' => $resourceId,
                        'terms' => implode(', ', $extraTerms),
                    ]
                );
            }
        }

        return $modified ? $data : null;
    }

    /**
     * Log template properties that are not used by any resource.
     */
    protected function reportUnusedProperties(
        ResourceTemplateRepresentation $template
    ): void {
        $unused = [];
        foreach ($this->usedProperties as $term => $used) {
            if (!$used) {
                $unused[] = $term;
            }
        }
        if ($unused) {
            $this->logger->notice(
                'Template "{label}": {count} properties not used by any resource: {terms}.', // @translate
                [
                    'label' => $template->label(),
                    'count' => count($unused),
                    'terms' => implode(', ', $unused),
                ]
            );
        }
    }

    /**
     * Log browse links for each issue, so user can directly view affected resources.
     *
     * Use search-based queries (property existence or non-existence) to avoid
     * url length issues on large collections.
     */
    protected function logBrowseLinks(int $templateId): void
    {
        if (!$this->extraPropertyIds
            && !$this->missingRequiredIds
        ) {
            return;
        }

        $url = $this->getServiceLocator()
            ->get('ViewHelperManager')->get('url');
        $controllerMap = [
            'items' => 'item',
            'item_sets' => 'item-set',
            'media' => 'media',
        ];

        foreach ($this->processedResourceTypes as $resourceType) {
            $controller = $controllerMap[$resourceType] ?? null;
            if (!$controller) {
                continue;
            }
            $route = [
                'controller' => $controller,
                'action' => 'browse',
            ];

            foreach ($this->extraPropertyIds as $term => $pid) {
                $link = $url('admin/default', $route, [
                    'query' => [
                        'resource_template_id' => $templateId,
                        'property' => [
                            ['property' => $pid, 'type' => 'ex'],
                        ],
                    ],
                ]);
                $this->logger->notice(
                    'Browse {resource_type} with extra property "{term}": {url}', // @translate
                    [
                        'resource_type' => $resourceType,
                        'term' => $term,
                        'url' => $link,
                    ]
                );
            }

            foreach ($this->missingRequiredIds as $term => $pid) {
                $link = $url('admin/default', $route, [
                    'query' => [
                        'resource_template_id' => $templateId,
                        'property' => [
                            ['property' => $pid, 'type' => 'nex'],
                        ],
                    ],
                ]);
                $this->logger->notice(
                    'Browse {resource_type} missing required "{term}": {url}', // @translate
                    [
                        'resource_type' => $resourceType,
                        'term' => $term,
                        'url' => $link,
                    ]
                );
            }
        }
    }

    /**
     * Try to convert a value to one of the allowed data types.
     *
     * Returns the converted value array or null if not possible.
     *
     * Conversion strategy by target main type:
     * - literal: always possible (use @value or uri or resource title);
     * - uri: only if @id is a valid URL;
     * - resource: only if value_resource_id is an existing integer resource;
     * - customvocab: only if the value matches a term in the vocab (literal), a
     *   URI in the vocab (uri), or an item in the vocab's item set (resource).
     *
     * @return array|null Converted value or null if not convertible.
     */
    protected function convertDataType(
        array $value,
        array $allowedTypes,
        int $resourceId,
        string $term
    ): ?array {
        $currentType = $value['type'] ?? 'literal';
        $literal = $value['@value'] ?? null;
        $uri = $value['@id'] ?? null;
        $resourceVid = $value['value_resource_id'] ?? null;

        foreach ($allowedTypes as $targetType) {
            $mainType = $this->easyMeta->dataTypeMain($targetType);

            // Custom vocab: check if value fits the vocab.
            if (strpos($targetType, 'customvocab:') === 0) {
                $converted = $this->convertToCustomVocab(
                    $value, $targetType
                );
                if ($converted !== null) {
                    $this->logger->info(
                        'Resource #{resource_id}: {term}: converted from "{from}" to "{to}".', // @translate
                        [
                            'resource_id' => $resourceId,
                            'term' => $term,
                            'from' => $currentType,
                            'to' => $targetType,
                        ]
                    );
                    return $converted;
                }
                continue;
            }

            if ($mainType === 'literal') {
                $text = $literal
                    ?? $uri
                    ?? ($value['o:label'] ?? null);
                if ($text === null && $resourceVid) {
                    try {
                        $res = $this->api->read(
                            'resources', (int) $resourceVid
                        )->getContent();
                        $text = (string) $res->displayTitle();
                    } catch (\Throwable $e) {
                        $text = (string) $resourceVid;
                    }
                }
                if ($text !== null && $text !== '') {
                    $this->logger->info(
                        'Resource #{resource_id}: {term}: converted from "{from}" to "{to}".', // @translate
                        [
                            'resource_id' => $resourceId,
                            'term' => $term,
                            'from' => $currentType,
                            'to' => $targetType,
                        ]
                    );
                    return [
                        'property_id' => $value['property_id'],
                        'type' => $targetType,
                        '@value' => $text,
                        'is_public' => $value['is_public'] ?? true,
                    ];
                }
            }

            if ($mainType === 'uri') {
                $candidateUri = $uri ?? $literal;
                if ($candidateUri
                    && filter_var($candidateUri, FILTER_VALIDATE_URL)
                ) {
                    $this->logger->info(
                        'Resource #{resource_id}: {term}: converted from "{from}" to "{to}".', // @translate
                        [
                            'resource_id' => $resourceId,
                            'term' => $term,
                            'from' => $currentType,
                            'to' => $targetType,
                        ]
                    );
                    return [
                        'property_id' => $value['property_id'],
                        'type' => $targetType,
                        '@id' => $candidateUri,
                        'o:label' => $value['o:label']
                            ?? ($uri ? $literal : null),
                        'is_public' => $value['is_public'] ?? true,
                    ];
                }
            }

            if ($mainType === 'resource') {
                $rid = $resourceVid
                    ?? (is_numeric($literal) ? (int) $literal : null);
                if ($rid) {
                    try {
                        $res = $this->api
                            ->read('resources', (int) $rid)
                            ->getContent();
                        // Check sub-type compatibility.
                        if (!$this->isResourceTypeMatch(
                            $res, $targetType
                        )) {
                            continue;
                        }
                        $this->logger->info(
                            'Resource #{resource_id}: {term}: converted from "{from}" to "{to}".', // @translate
                            [
                                'resource_id' => $resourceId,
                                'term' => $term,
                                'from' => $currentType,
                                'to' => $targetType,
                            ]
                        );
                        return [
                            'property_id' => $value['property_id'],
                            'type' => $targetType,
                            'value_resource_id' => (int) $rid,
                            'is_public' => $value['is_public'] ?? true,
                        ];
                    } catch (\Throwable $e) {
                        // Resource does not exist.
                    }
                }
            }
        }

        return null;
    }

    /**
     * Try to convert a value to a custom vocab data type.
     *
     * Return converted value array or null if value does not match the vocab.
     */
    protected function convertToCustomVocab(
        array $value,
        string $targetType
    ): ?array {
        $vocabId = (int) substr($targetType, strlen('customvocab:'));
        if (!$vocabId) {
            return null;
        }

        try {
            /** @var \CustomVocab\Api\Representation\CustomVocabRepresentation $vocab */
            $vocab = $this->api
                ->read('custom_vocabs', $vocabId)
                ->getContent();
        } catch (\Throwable $e) {
            return null;
        }

        $literal = $value['@value'] ?? null;
        $uri = $value['@id'] ?? null;
        $resourceVid = $value['value_resource_id'] ?? null;

        switch ($vocab->type()) {
            case 'literal':
                $terms = $vocab->terms();
                $text = $literal ?? $uri;
                if ($text !== null && in_array($text, $terms)) {
                    return [
                        'property_id' => $value['property_id'],
                        'type' => $targetType,
                        '@value' => $text,
                        'is_public' => $value['is_public'] ?? true,
                    ];
                }
                break;

            case 'uri':
                $uris = $vocab->uris();
                $candidateUri = $uri ?? $literal;
                if ($candidateUri
                    && array_key_exists($candidateUri, $uris)
                ) {
                    return [
                        'property_id' => $value['property_id'],
                        'type' => $targetType,
                        '@id' => $candidateUri,
                        'o:label' => $uris[$candidateUri]
                            ?? ($value['o:label'] ?? null),
                        'is_public' => $value['is_public'] ?? true,
                    ];
                }
                break;

            case 'resource':
                $itemSet = $vocab->itemSet();
                if (!$itemSet) {
                    break;
                }
                $rid = $resourceVid
                    ?? (is_numeric($literal) ? (int) $literal : null);
                if (!$rid) {
                    break;
                }
                // Check if the resource belongs to the item set.
                try {
                    $item = $this->api
                        ->read('items', (int) $rid)
                        ->getContent();
                    foreach ($item->itemSets() as $is) {
                        if ($is->id() === $itemSet->id()) {
                            return [
                                'property_id' => $value['property_id'],
                                'type' => $targetType,
                                'value_resource_id' => (int) $rid,
                                'is_public' => $value['is_public'] ?? true,
                            ];
                        }
                    }
                } catch (\Throwable $e) {
                    // Resource does not exist.
                }
                break;
        }

        return null;
    }

    /**
     * Check if a resource representation matches a target data type.
     *
     * Omeka manages:
     * - "resource" accepts any;
     * - "resource:item" only items;
     * - "resource:media" only media;
     * - "resource:itemset" only item sets;
     * - "resource:annotation" only annotation.
     */
    protected function isResourceTypeMatch(
        $resource,
        string $targetType
    ): bool {
        if ($targetType === 'resource') {
            return true;
        }
        $class = get_class($resource);
        $map = [
            'resource:item' => 'Omeka\Api\Representation\ItemRepresentation',
            'resource:media' => 'Omeka\Api\Representation\MediaRepresentation',
            'resource:itemset' => 'Omeka\Api\Representation\ItemSetRepresentation',
            'resource:annotation' => 'Annotate\Api\Representation\AnnotationRepresentation',
        ];
        return isset($map[$targetType])
            && $class === $map[$targetType];
    }

    /**
     * Map api resource type name to its Doctrine entity class (discriminator).
     */
    protected function resourceTypeToEntityClass(string $resourceType): ?string
    {
        $map = [
            'items' => 'Omeka\Entity\Item',
            'item_sets' => 'Omeka\Entity\ItemSet',
            'media' => 'Omeka\Entity\Media',
            'annotation' => 'Annotate\Entity\Annotation',
        ];
        return $map[$resourceType] ?? null;
    }
}

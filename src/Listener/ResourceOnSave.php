<?php declare(strict_types=1);

namespace AdvancedResourceTemplate\Listener;

use AdvancedResourceTemplate\Api\Representation\ResourceTemplatePropertyDataRepresentation;
use AdvancedResourceTemplate\Api\Representation\ResourceTemplateRepresentation;
use Common\Stdlib\PsrMessage;
use Exception;
use Laminas\EventManager\Event;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Entity\ResourceTemplate;
use Omeka\Entity\Value;
use Omeka\Stdlib\ErrorStore;

class ResourceOnSave
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
     * @var \Laminas\ServiceManager\ServiceLocatorInterface
     */
    protected $services;

    public function __construct(ServiceLocatorInterface $services)
    {
        $this->services = $services;
        $this->api = $services->get('Omeka\ApiManager');
        $this->easyMeta = $services->get('Common\EasyMeta');
    }

    public function handleTemplateSettingsOnSave(Event $event): void
    {
        /** @var \Omeka\Api\Request $request */
        $request = $event->getParam('request');

        // This is the resource representation array passed to the api for
        // creation/update.
        $resource = $request->getContent();

        $template = $resource['o:resource_template'] ?? null;
        if (!$template) {
            return;
        }

        // The method jsonSerialize() does not encode objects as array (owner,
        // resource template, etc.) currently.
        $templateId = is_object($template) ? $template->id() : $template['o:id'] ?? null;
        if (!$templateId) {
            return;
        }

        try {
            /** @var \AdvancedResourceTemplate\Api\Representation\ResourceTemplateRepresentation $template */
            $template = $this->api->read('resource_templates', ['id' => $templateId])->getContent();
        } catch (Exception $e) {
            return;
        }

        // Prepare value annotations level.
        $vaTemplateDefault = null;
        $vaTemplateDefaultId = $template->dataValue('value_annotations_template');
        if (is_numeric($vaTemplateDefault)) {
            try {
                /** @var \AdvancedResourceTemplate\Api\Representation\ResourceTemplateRepresentation $vaTemplateDefault */
                $vaTemplateDefault = $this->api->read('resource_templates', ['id' => $vaTemplateDefaultId])->getContent();
            } catch (Exception $e) {
            }
        }

        $type = $request->getResource() ?: $resource['@type'] ?? null;
        $isItem = in_array($type, ['o:Item', 'items'])
            || (is_array($type) && in_array('o:Item', $type));

        // Template level.

        if ($isItem) {
            $resource = $this->appendAutomaticItemSets($template, $resource);
        }

        $resource = $this->appendAutomaticValuesFromTemplateData($template, $resource);

        // Property level.

        foreach ($template->resourceTemplateProperties() as $templateProperty) {
            foreach ($templateProperty->data() as $rtpData) {
                // Manage the split separator.
                $resource = $this->explodeValueFromTemplatePropertyData($rtpData, $resource);
                // Append the automatic values.
                $automaticValues = $this->automaticValuesFromTemplatePropertyData($rtpData, $resource);
                foreach ($automaticValues as $automaticValue) {
                    $resource[$templateProperty->property()->term()][] = $automaticValue;
                }
                // Order by linked resource property values.
                $resource = $this->orderByLinkedResourcePropertyData($rtpData, $resource);
                // Value annotations level.
                $resource = $this->handleVaTemplateSettings($resource, $rtpData, $vaTemplateDefault);
            }
        }

        $request->setContent($resource);
    }

    protected function handleVaTemplateSettings(
        array $resource,
        ResourceTemplatePropertyDataRepresentation $rtpData,
        ?ResourceTemplateRepresentation $vaTemplateDefault
    ): array {
        // Check if there is something to process.
        // Unlike resource, don't add default value if there is no value.
        $propertyTerm = $rtpData->property()->term();
        if (empty($resource[$propertyTerm])) {
            return $resource;
        }

        $vaTemplate = null;
        $vaTemplateDefaultId = $vaTemplateDefault ? $vaTemplateDefault->id() : null;
        $vaTemplateId = $rtpData->dataValue('value_annotations_template');
        if (empty($vaTemplateId) || (int) $vaTemplateId === $vaTemplateDefaultId) {
            $vaTemplate = $vaTemplateDefault;
        } elseif (is_numeric($vaTemplateId)) {
            try {
                /** @var \AdvancedResourceTemplate\Api\Representation\ResourceTemplateRepresentation $vaTemplate */
                $vaTemplate = $this->api->read('resource_templates', ['id' => $vaTemplateId])->getContent();
            } catch (Exception $e) {
            }
        }

        if (!$vaTemplate) {
            return $resource;
        }

        // Here the resource is the value annotation.

        foreach ($resource[$propertyTerm] as $index => $value) {
            $vaResource = $value['@annotation'] ?? [];

            // Value annotation template level.
            $vaResource = $this->appendAutomaticValuesFromTemplateData($vaTemplate, $vaResource);

            // Value annotation property level.
            foreach ($vaTemplate->resourceTemplateProperties() as $vaTemplateProperty) {
                foreach ($vaTemplateProperty->data() as $vaRtpData) {
                    // Manage the split separator.
                    $vaResource = $this->explodeValueFromTemplatePropertyData($vaRtpData, $vaResource);
                    // Append the automatic values.
                    $automaticValues = $this->automaticValuesFromTemplatePropertyData($vaRtpData, $vaResource);
                    foreach ($automaticValues as $automaticValue) {
                        $vaResource[$vaTemplateProperty->property()->term()][] = $automaticValue;
                    }
                    // Order by linked resource property values.
                    $vaResource = $this->orderByLinkedResourcePropertyData($vaRtpData, $vaResource);
                }
            }

            $resource[$propertyTerm][$index]['@annotation'] = $vaResource;
        }

        return $resource;
    }

    public function validateEntityHydratePost(Event $event): void
    {
        /** @var \Omeka\Entity\Resource $entity */
        $entity = $event->getParam('entity');

        /** @var \Omeka\Entity\ResourceTemplate $templateEntity */
        $templateEntity = $entity->getResourceTemplate();
        if (!$templateEntity) {
            return;
        }

        // Update open custom vocabs in any cases, when checks are skipped.
        $this->updateCustomVocabOpen($event);

        $settings = $this->services->get('Omeka\Settings');
        $skipChecks = (bool) $settings->get('advancedresourcetemplate_skip_checks');
        if ($skipChecks) {
            return;
        }

        /**
         * @var \Omeka\Api\Adapter\AbstractResourceEntityAdapter $adapter
         * @var \AdvancedResourceTemplate\Api\Representation\ResourceTemplateRepresentation $template
         * @var \Omeka\Api\Request $request
         * @var \Omeka\Stdlib\ErrorStore $errorStore
         * @var \Doctrine\DBAL\Connection $connection
         */
        $adapter = $event->getTarget();
        $template = $adapter->getAdapter('resource_templates')->getRepresentation($templateEntity);
        // $request = $event->getParam('request');
        $errorStore = $event->getParam('errorStore');

        $directMessage = $this->displayDirectMessage();
        $messenger = $directMessage ? $this->services->get('ControllerPluginManager')->get('messenger') : null;

        // Template level.

        $useForResources = $template->dataValue('use_for_resources') ?: [];
        $resourceName = $entity->getResourceName();

        if ($useForResources && !in_array($resourceName, $useForResources)) {
            $message = new PsrMessage('This template cannot be used for this resource.'); // @translate
            $errorStore->addError('o:resource_template[o:id]', $message);
            if ($directMessage) {
                $messenger->addError($message);
            }
        }

        $resourceClass = $entity->getResourceClass();
        $requireClass = $this->valueIsTrue($template->dataValue('require_resource_class'));
        if ($requireClass && !$resourceClass) {
            $message = new PsrMessage('A class is required.'); // @translate
            $errorStore->addError('o:resource_class[o:id]', $message);
        }

        $closedClassList = $this->valueIsTrue($template->dataValue('closed_class_list'));
        if ($closedClassList && $resourceClass) {
            $suggestedClasses = $template->dataValue('suggested_resource_class_ids') ?: [];
            if ($suggestedClasses && !in_array($resourceClass->getId(), $suggestedClasses)) {
                if (count($suggestedClasses) === 1) {
                    $message = new PsrMessage(
                        'The class should be {resource_class}.', // @translate
                        ['resource_class' => key($suggestedClasses)]
                    );
                    $errorStore->addError('o:resource_class[o:id]', $message);
                } else {
                    $message = new PsrMessage(
                        'The class should be one of {resource_classes}.', // @translate
                        ['resource_classes' => implode(', ', array_keys($suggestedClasses))]
                    );
                    $errorStore->addError('o:resource_class[o:id]', $message);
                }
            }
        }

        if ($entity instanceof \Omeka\Entity\Item) {
            $requireMedias = array_filter($template->dataValue('media_templates_minimum') ?? []);
            if ($requireMedias) {
                $medias = $entity->getMedia();
                // Do a quick check first.
                if (count($medias) < array_sum($requireMedias)) {
                    $message = new PsrMessage(
                        'The minimum number of files or medias is {min}.', // @translate
                        ['min' => array_sum($requireMedias)]
                    );
                    $errorStore->addError('o:media', $message);
                } else {
                    /** @var \Omeka\Entity\Media $media */
                    // Prepare the total of medias by template.
                    $countMediasByTemplate = [];
                    foreach ($medias as $media) {
                        $template = $media->getResourceTemplate();
                        if ($template) {
                            $templateId = $template->getId();
                            $templateLabel = $template->getLabel();
                        } else {
                            $templateId = 0;
                            $templateLabel = '';
                        }
                        $countMediasByTemplate[$templateId] = isset($countMediasByTemplate[$templateId])
                            ? ++$countMediasByTemplate[$templateId]
                            : 1;
                        $countMediasByTemplate[$templateLabel] = isset($countMediasByTemplate[$templateLabel])
                            ? ++$countMediasByTemplate[$templateLabel]
                            : 1;
                    }
                    foreach ($requireMedias as $mediaTemplateIdOrLabel => $min) {
                        if (!isset($countMediasByTemplate[$mediaTemplateIdOrLabel])
                            || $countMediasByTemplate[$mediaTemplateIdOrLabel] < $min
                        ) {
                            $message = new PsrMessage(
                                'The minimum number of files or medias is {min}.', // @translate
                                ['min' => array_sum($requireMedias)]
                            );
                            // Display only one message for end user.
                            break;
                        }
                    }
                }
            }
        }

        // TODO Manage closed property list: but good data can be added via modules (identifier, etc.).

        // Some checks can be done simpler via representation.
        /** @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource */
        $resource = $adapter->getRepresentation($entity);

        // Property level.
        $this->validateResourceProperty($resource, $errorStore, $directMessage);
    }

    protected function validateResourceProperty(
        AbstractResourceEntityRepresentation $resource,
        ErrorStore $errorStore,
        bool $directMessage
    ): void {
        $template = $resource->resourceTemplate();
        $resourceId = (int) $resource->id();
        $messenger = $directMessage ? $this->services->get('ControllerPluginManager')->get('messenger') : null;

        // Warning: to use $resource->jsonSerialize() here for debug output a doctrine error.

        foreach ($template->resourceTemplateProperties() as $templateProperty) {
            foreach ($templateProperty->data() as $rtpData) {
                $property = $templateProperty->property();
                $propertyId = $property->id();
                $propertyTerm = $property->term();
                $inputControl = (string) $rtpData->dataValue('input_control');
                if (strlen($inputControl)) {
                    // Check that the input control is a valid regex first.
                    $anchors = ['/', '#', '~', '%', '`', ';', '§', 'µ'];
                    foreach ($anchors as $anchor) {
                        if (mb_strpos($inputControl, $anchor) === false) {
                            $regex = $anchor . '^(?:' . $inputControl . ')$' . $anchor . 'u';
                            if (preg_match($regex, '') === false) {
                                $anchor = '';
                            }
                            break;
                        }
                    }
                    if (empty($anchor) || empty($regex)) {
                        $message = new PsrMessage(
                            'The html input pattern "{pattern}" for template {template} cannot be processed.', // @translate
                            ['pattern' => $inputControl, 'template' => $template->label()]
                        );
                        $this->services->get('Omeka\Logger')->warn((string) $message);
                    } else {
                        foreach ($resource->value($propertyTerm, ['all' => true, 'type' => 'literal']) as $value) {
                            $val = $value->value();
                            if (!preg_match($regex, $val)) {
                                $message = new PsrMessage(
                                    'The value "{value}" for term {property} does not follow the input pattern "{pattern}".', // @translate
                                    ['value' => $val, 'property' => $propertyTerm, 'pattern' => $inputControl]
                                );
                                $errorStore->addError($propertyTerm, $message);
                                if ($directMessage) {
                                    $messenger->addError($message);
                                }
                            }
                        }
                    }
                }

                $minLength = (int) $rtpData->dataValue('min_length');
                $maxLength = (int) $rtpData->dataValue('max_length');
                if ($minLength || $maxLength) {
                    foreach ($resource->value($propertyTerm, ['all' => true, 'type' => 'literal']) as $value) {
                        $length = mb_strlen($value->value());
                        if ($minLength && $length < $minLength) {
                            $message = new PsrMessage(
                                'The value for term {property} is shorter ({length} characters) than the minimal size ({number} characters).', // @translate
                                ['property' => $propertyTerm, 'length' => $length, 'number' => $minLength]
                            );
                            $errorStore->addError($propertyTerm, $message);
                            if ($directMessage) {
                                $messenger->addError($message);
                            }
                        }
                        if ($maxLength && $length > $maxLength) {
                            $message = new PsrMessage(
                                'The value for term {property} is longer ({length} characters) than the maximal size ({number} characters).', // @translate
                                ['property' => $propertyTerm, 'length' => $length, 'number' => $maxLength]
                            );
                            $errorStore->addError($propertyTerm, $message);
                            if ($directMessage) {
                                $messenger->addError($message);
                            }
                        }
                    }
                }

                $minValues = (int) $rtpData->dataValue('min_values');
                $maxValues = (int) $rtpData->dataValue('max_values');
                // TODO Fix api($form) to manage the minimum number of values in admin resource form.
                // The check for directMessage is to be removed with the fix.
                if (!$directMessage && ($minValues || $maxValues)) {
                    // The number of values may be specific for each type.
                    $isRequired = $rtpData->isRequired();
                    $values = $resource->value($propertyTerm, ['all' => true, 'type' => $rtpData->dataTypes()]);
                    $countValues = count($values);
                    if ($isRequired && $minValues && $countValues < $minValues) {
                        $message = new PsrMessage(
                            'The number of values ({count}) for term {property} is lower than the minimal number of {number}.', // @translate
                            ['count' => $countValues, 'property' => $propertyTerm, 'number' => $minValues]
                        );
                        $errorStore->addError($propertyTerm, $message);
                        if ($directMessage) {
                            $messenger->addError($message);
                        }
                        break;
                    }
                    if ($maxValues && $countValues > $maxValues) {
                        $message = new PsrMessage(
                            'The number of values ({count}) for term {property} is greater than the maximal number of {number}.', // @translate
                            ['count' => $countValues, 'property' => $propertyTerm, 'number' => $maxValues]
                        );
                        $errorStore->addError($propertyTerm, $message);
                        if ($directMessage) {
                            $messenger->addError($message);
                        }
                        break;
                    }
                }

                $uniqueValue = (bool) $rtpData->dataValue('unique_value');
                if ($uniqueValue) {
                    $values = $resource->value($propertyTerm, ['all' => true]);
                    if ($values) {
                        $connection = $this->services->get('Omeka\Connection');
                        $sqlWhere = [];
                        // Get all values by main type in one query.
                        $bind = [
                            'resource_id' => $resourceId,
                            'property_id' => $propertyId,
                        ];
                        $types = [
                            'resource_id' => \Doctrine\DBAL\ParameterType::INTEGER,
                            'property_id' => \Doctrine\DBAL\ParameterType::INTEGER,
                        ];
                        foreach ($values as $value) {
                            if ($k = $value->valueResource()) {
                                $bind['resource'][] = $k->id();
                            } elseif ($k = $value->uri()) {
                                $bind['uri'][] = $k;
                            } else {
                                $bind['literal'][] = $value->value();
                            }
                        }
                        if (isset($bind['resource'])) {
                            $sqlWhere[] = 'value.value_resource_id IN (:resource)';
                            $types['resource'] = $connection::PARAM_INT_ARRAY;
                        }
                        if (isset($bind['uri'])) {
                            $sqlWhere[] = 'value.uri IN (:uri)';
                            $types['uri'] = $connection::PARAM_STR_ARRAY;
                        }
                        if (isset($bind['literal'])) {
                            $sqlWhere[] = 'value.value IN (:literal)';
                            $types['literal'] = $connection::PARAM_STR_ARRAY;
                        }
                        $sqlWhere = implode(' OR ', $sqlWhere);
                        $sql = <<<SQL
                            SELECT value.resource_id
                            FROM value
                            WHERE value.resource_id != :resource_id
                                AND value.property_id = :property_id
                                AND ($sqlWhere)
                            LIMIT 1;
                            SQL;
                        $resId = $connection->executeQuery($sql, $bind, $types)->fetchOne();
                        if ($resId) {
                            $message = new PsrMessage(
                                'The value for term {property} should be unique, but already set for resource #{resource_id}.', // @translate
                                ['property' => $propertyTerm, 'resource_id' => $resId]
                            );
                            $errorStore->addError($propertyTerm, $message);
                            if ($directMessage) {
                                $messenger->addError($message);
                            }
                            break;
                        }
                    }
                }

                // TODO Check language (but they are suggested languages).
            }
        }
    }

    /**
     * The resource template of the value annotation is not stored, so get it
     * from main resource template and term.
     *
     * The template is saved in all cases, even if there is no main template.
     */
    public function storeVaTemplates(Event $event): void
    {
        /**
         * @var \Omeka\Api\Response $response
         * @var \Omeka\Entity\Resource $resource
         * @var \Omeka\Entity\Value $value
         * @var \Omeka\Entity\ValueAnnotation $valueAnnotation
         */
        $response = $event->getParam('response');
        $resource = $response->getContent('resource');
        $template = $resource->getResourceTemplate();

        if (!$template) {
            return;
        }

        $entityManager = $this->services->get('Omeka\EntityManager');

        $vaDefaultTemplate = null;
        $rtData = $entityManager->getRepository(\AdvancedResourceTemplate\Entity\ResourceTemplateData::class)
            ->findOneBy(['resourceTemplate' => $template->getId()]);
        if ($rtData) {
            // Option "none" is managed like empty here.
            $vaDefaultTemplateId = (int) $rtData->getDataValue('value_annotations_template');
            if ($vaDefaultTemplateId) {
                $vaDefaultTemplate = $entityManager->find(\Omeka\Entity\ResourceTemplate::class, $vaDefaultTemplateId);
            }
        }

        foreach ($resource->getValues() as $value) {
            $valueAnnotation = $value->getValueAnnotation();
            if ($valueAnnotation) {
                $vaTemplate = $template
                    ? $this->getVaTemplate($value, $template, $vaDefaultTemplate)
                    : null;
                if ($vaTemplate) {
                    $valueAnnotation->setResourceTemplate($vaTemplate);
                    $valueAnnotation->setResourceClass($vaTemplate->getResourceClass());
                } else {
                    $valueAnnotation->setResourceTemplate(null);
                    $valueAnnotation->setResourceClass(null);
                }
            }
        }

        $entityManager->flush();
    }

    protected function getVaTemplate(
        Value $value,
        ResourceTemplate $template,
        ?ResourceTemplate $vaDefaultTemplate
    ): ?ResourceTemplate {
        // TODO Manage specific value annotation template by data type.

        // Normally, the templates are cached by doctrine.
        $vaTemplate = null;
        $vaTemplateOption = null;

        $entityManager = $this->services->get('Omeka\EntityManager');

        $property = $value->getProperty();
        $rtp = $entityManager->getRepository(\Omeka\Entity\ResourceTemplateProperty::class)
            ->findOneBy([
                'resourceTemplate' => $template->getId(),
                'property' => $property->getId(),
            ]);
        if ($rtp) {
            $rtpData = $entityManager->getRepository(\AdvancedResourceTemplate\Entity\ResourceTemplatePropertyData::class)
                ->findOneBy([
                    'resourceTemplate' => $template->getId(),
                    'resourceTemplateProperty' => $rtp->getId(),
                ], ['id' => 'ASC']);
            if ($rtpData) {
                // Options "none" and "manual" are possible.
                $vaTemplateOption = $rtpData->getDataValue('value_annotations_template');
                if (is_numeric($vaTemplateOption)) {
                    $vaTemplate = $entityManager->find(\Omeka\Entity\ResourceTemplate::class, (int) $vaTemplateOption);
                    if ($vaTemplate) {
                        return $vaTemplate;
                    }
                }
            }
        }

        // Don't return default template if property option is to keep it "none"
        // or "manual" or invalid.
        return empty($vaTemplateOption)
            ? $vaDefaultTemplate
            : null;
    }

    /**
     * Check if messages should be displayed to end user.
     *
     * Because the form doesn't contain the properties, that are added
     * dynamically, and because the resource controllers don't include the
     * stored messages from create/update events, error messages may be added
     * directly.
     *
     * @todo Include the check in the resource form. Add a fake hidden element? Or fix api plugin (the form is static in plugin api, so it is removed when called somewhere else)? For now, just js (issue is only on the min/max numbers of values).
     */
    protected function displayDirectMessage(): bool
    {
        /** @var \Omeka\Mvc\Status $status */
        $status = $this->services->get('Omeka\Status');
        $routeMatch = $status->getRouteMatch();
        // RouteMatch may be unavailable during background process.
        $routeName = $routeMatch ? $routeMatch->getMatchedRouteName() : null;
        // Module Contribute can use the error store so don't output issue here.
        return $routeName === 'admin/default'
            && in_array($routeMatch->getParam('__CONTROLLER__'), ['item', 'item-set', 'media', 'annotation'])
            && in_array($routeMatch->getParam('action'), ['add', 'edit']);
    }

    /**
     * Update open custom vocabs with new terms.
     */
    protected function updateCustomVocabOpen(Event $event): void
    {
        /** @var \Omeka\Entity\Resource $entity */
        $entity = $event->getParam('entity');

        /** @var \Omeka\Entity\ResourceTemplate $templateEntity */
        $templateEntity = $entity->getResourceTemplate();
        if (!$templateEntity) {
            return;
        }

        /**
         * @var \Omeka\Api\Adapter\AbstractResourceEntityAdapter $adapter
         * @var \AdvancedResourceTemplate\Api\Representation\ResourceTemplateRepresentation $template
         * @var \Omeka\Api\Request $request
         * @var \Omeka\Stdlib\ErrorStore $errorStore
         * @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource
         * @var \Omeka\Api\Manager $api
         * @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger
         */
        $adapter = $event->getTarget();
        $template = $adapter->getAdapter('resource_templates')->getRepresentation($templateEntity);
        $resource = $adapter->getRepresentation($entity);

        // First, quick check if some custom vocabs are open.
        $hasCustomVocabOpen = false;
        foreach ($template->resourceTemplateProperties() as $templateProperty) {
            foreach ($templateProperty->data() as $rtpData) {
                if ($this->valueIsTrue($rtpData->dataValue('custom_vocab_open'))) {
                    $hasCustomVocabOpen = true;
                    break 2;
                }
            }
        }

        if (!$hasCustomVocabOpen) {
            return;
        }

        $api = $this->services->get('Omeka\ApiManager');

        $customVocabs = [];

        // Only literal custom vocabs are managed for now, but no query.
        /** @var \CustomVocab\Api\Representation\CustomVocabRepresentation $customVocab */
        foreach ($api->search('custom_vocabs')->getContent() as $customVocab) {
            // CustomVocab v2.0.0 changed method names.
            $customVocabType = method_exists($customVocab, 'typeValues')
                ? $customVocab->typeValues()
                : $customVocab->type();
            if ($customVocabType === 'literal') {
                $id = $customVocab->id();
                $customVocabs['customvocab:' . $id] = [
                    'id' => $id,
                    'label' => $customVocab->label(),
                    'terms' => $customVocab->listValues(),
                    'new' => [],
                    'term' => null,
                ];
            }
        }
        if (!$customVocabs) {
            return;
        }

        foreach ($template->resourceTemplateProperties() as $templateProperty) {
            foreach ($templateProperty->data() as $rtpData) {
                if (!$this->valueIsTrue($rtpData->dataValue('custom_vocab_open'))) {
                    continue;
                }
                $propertyTerm = $templateProperty->property()->term();
                foreach ($resource->value($propertyTerm, ['all' => true, 'type' => array_keys($customVocabs)]) as $value) {
                    $val = trim((string) $value->value());
                    $dataType = $value->type();
                    if (strlen($val) && !in_array($val, $customVocabs[$dataType]['terms'])) {
                        $customVocabs[$dataType]['term'] = $propertyTerm;
                        $customVocabs[$dataType]['new'][] = $val;
                    }
                }
            }
        }

        /** @var \Omeka\Permissions\Acl $acl */
        $acl = $this->services->get('Omeka\Acl');
        $roles = $acl->getRoles();
        $acl
            ->allow(
                $roles,
                [\CustomVocab\Api\Adapter\CustomVocabAdapter::class],
                ['update']
            );

        $errorStore = $event->getParam('errorStore');
        $directMessage = $this->displayDirectMessage();
        $messenger = $directMessage ? $this->services->get('ControllerPluginManager')->get('messenger') : null;

        foreach ($customVocabs as $customVocab) {
            if (!$customVocab['new']) {
                continue;
            }
            // Update custom vocab.
            $newTerms = array_merge($customVocab['terms'], $customVocab['new']);
            try {
                $api->update('custom_vocabs', $customVocab['id'], ['o:terms' => $newTerms], [], ['isPartial' => true]);
                if ($directMessage) {
                    if (count($customVocab['new']) <= 1) {
                        $message = new PsrMessage(
                            'New descriptor appended to custom vocab "{custom_vocab}": {value}.', // @translate
                            ['custom_vocab' => $customVocab['label'], 'value' => $customVocab['new']]
                        );
                    } else {
                        $message = new PsrMessage(
                            'New descriptors appended to custom vocab "{custom_vocab}": {values}.', // @translate
                            ['custom_vocab' => $customVocab['label'], 'values' => implode('", "', $customVocab['new'])]
                        );
                    }
                    $messenger->addSuccess($message);
                }
            } catch (Exception $e) {
                $message = new PsrMessage(
                    'Unable to append new descriptors to custom vocab "{custom_vocab}":  {error}', // @translate
                    ['custom_vocab' => $customVocab['label'], 'error' => $e->getMessage()]
                );
                $errorStore->addError($customVocab['term'], $message);
                if ($directMessage) {
                    $messenger->addError($message);
                }
            }
        }
    }

    protected function appendAutomaticItemSets(
        \AdvancedResourceTemplate\Api\Representation\ResourceTemplateRepresentation $template,
        array $resource
    ): array {
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

        $itemSets = $newItemSets;

        // Use connexion to avoid issue with rights.
        $connection = $this->services->get('Omeka\Connection');
        $sql = 'SELECT `id`, `id` FROM `item_set` WHERE `id` IN (:item_set_ids) ORDER BY `id`';
        $itemSetIds = $connection->executeQuery($sql, ['item_set_ids' => $itemSets], ['item_set_ids' => \Doctrine\DBAL\Connection::PARAM_INT_ARRAY])->fetchAllKeyValue();
        if (!$itemSetIds) {
            return $resource;
        }

        foreach ($newItemSets as $itemSetId) {
            $resource['o:item_set'][] = ['o:id' => $itemSetId];
        }

        return $resource;
    }

    protected function appendAutomaticValuesFromTemplateData(
        \AdvancedResourceTemplate\Api\Representation\ResourceTemplateRepresentation $template,
        array $resource
    ): array {
        $automaticValues = trim((string) $template->dataValue('automatic_values'));
        if ($automaticValues === '') {
            return $resource;
        }

        // The method is currently not available here, so use the module.
        $mod = new \AdvancedResourceTemplate\Module();
        $mod->setServiceLocator($this->getServiceLocator());
        $mapping = $mod->stringToAutofillers("[automatic_values]\n$automaticValues");
        if (!$mapping || !$mapping['automatic_values']['mapping']) {
            return $resource;
        }

        /**
         * @var \AdvancedResourceTemplate\Mvc\Controller\Plugin\ArtMapper $mapper
         */
        $mapper = $this->services->get('ControllerPluginManager')->get('artMapper');

        $newResourceData = $mapper
            ->setMapping($mapping['automatic_values']['mapping'])
            ->setIsSimpleExtract(false)
            ->setIsInternalSource(true)
            ->array($resource);

        // Append only new data.
        foreach ($newResourceData as $propertyTerm => $newValues) {
            foreach ($newValues as $newValue) {
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
                foreach ($resource[$propertyTerm] ?? [] as $value) {
                    $checkValue = array_intersect_key($value, $check);
                    if (isset($checkValue['value_resource_id'])) {
                        $checkValue['value_resource_id'] = (int) $checkValue['value_resource_id'];
                    }
                    ksort($checkValue);
                    if ($check === $checkValue) {
                        continue 2;
                    }
                }
                $resource[$propertyTerm][] = $newValue;
            }
        }

        return $resource;
    }

    protected function explodeValueFromTemplatePropertyData(
        \AdvancedResourceTemplate\Api\Representation\ResourceTemplatePropertyDataRepresentation $rtpData,
        array $resource
    ): array {
        // Explode value requires a literal value.
        if ($rtpData->dataType() !== 'literal') {
            return $resource;
        }

        $separator = trim((string) $rtpData->dataValue('split_separator'));
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

    protected function automaticValuesFromTemplatePropertyData(
        \AdvancedResourceTemplate\Api\Representation\ResourceTemplatePropertyDataRepresentation $rtpData,
        array $resource
    ): array {
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
            // May output a null.
            $value = $this->appendAutomaticPropertyValueToResource($resource, [
                'data_types' => $rtpData->dataTypes(),
                'is_public' => !$rtpData->isPrivate(),
                'term' => $propertyTerm,
                'property_id' => $propertyId,
                'value' => $automaticValue,
            ]);
            if (!empty($value)) {
                $values[] = $value;
            }
        }

        if ($automaticValuesIssued === 'first') {
            // If a value is already set, skip it with mode "first".
            $isPublic = !empty($resource['o:is_public']);
            $hasNoValue = empty($values) && empty($resource[$propertyTerm]);
            if ($isPublic && $hasNoValue) {
                // May output a null.
                $value = $this->appendAutomaticPropertyValueToResource($resource, [
                    'data_types' => $rtpData->dataTypes(),
                    'is_public' => !$rtpData->isPrivate(),
                    'term' => $propertyTerm,
                    'property_id' => $propertyId,
                    // Allow to manage literal and numeric timestamp.
                    'value' => (new \DateTime)->format('Y-m-d'),
                ]);
                if (!empty($value)) {
                    $values[] = $value;
                }
            }
        }

        return $values;
    }

    protected function appendAutomaticPropertyValueToResource(
        array $resource,
        ?array $map
    ): ?array {
        if (empty($map) || empty($map['property_id'])) {
            return null;
        }

        $propertyTerm = $map['term'];
        $propertyId = $map['property_id'];
        $automaticValue = $map['value'];
        $dataTypes = $map['data_types'];
        $isPublic = $map['is_public'] ?? true;
        // Use the first data type by default.
        $dataType = count($dataTypes) ? reset($dataTypes) : 'literal';

        /**
         * @var \Omeka\Api\Manager $api
         * @var \AdvancedResourceTemplate\Mvc\Controller\Plugin\FieldNameToProperty $fieldNameToProperty
         * @var \AdvancedResourceTemplate\Mvc\Controller\Plugin\ArtMapper $mapper
         */
        $plugins = $this->services->get('ControllerPluginManager');
        $api = $this->services->get('Omeka\ApiManager');
        $fieldNameToProperty = $plugins->get('fieldNameToProperty');
        $mapper = $plugins->get('artMapper');

        // TODO Use mapper metaMapper from module Bulk Import (json dot notation or jmespath + basic twig).

        // Only the main rdf data is checked for transformation.

        $automaticValueArray = json_decode($automaticValue, true);
        if (is_array($automaticValueArray)) {
            if (empty($automaticValueArray['type'])) {
                $automaticValueArray['type'] = $dataType;
            } else {
                // Check validity of the data type.
                /** @var \Omeka\DataType\Manager $dataTypeManager */
                $dataTypeManager = $this->services->get('Omeka\DataTypeManager');
                if (!$dataTypeManager->has($automaticValueArray['type'])) {
                    return null;
                }
                if ($dataTypes && !in_array($automaticValueArray['type'], $dataTypes)) {
                    return null;
                }
            }
            // Check the validity of the data with the data type.
            $dataType = $automaticValueArray['type'];
            $mainType = $this->easyMeta->dataTypeMain($dataType);

            switch ($mainType) {
                case 'resource':
                    if (empty($automaticValue['value_resource_id'])) {
                        return null;
                    }
                    $vrid = $automaticValue['value_resource_id'];
                    $to = "$propertyTerm ^^$dataType ~ $vrid";
                    $to = $fieldNameToProperty($to);
                    if (!$to) {
                        return null;
                    }
                    $automaticValue['value_resource_id'] = (int) $mapper
                        ->setMapping([])
                        ->setIsSimpleExtract(false)
                        ->setIsInternalSource(true)
                        ->extractValueOnly($resource, ['from' => '~', 'to' => $to]);

                    // Check the value.
                    try {
                        $api->read('resources', ['id' => $vrid], ['initialize' => false, 'finalize' => false]);
                    } catch (Exception $e) {
                        return null;
                    }
                    $check = array_intersect_key($automaticValueArray, ['type' => null, 'value_resource_id' => null]);
                    break;

                case 'uri':
                    if (empty($automaticValue['@id'])) {
                        return null;
                    }
                    $uri = $automaticValue['@id'];
                    $to = "$propertyTerm ^^$dataType ~ $uri";
                    $to = $fieldNameToProperty($to);
                    if (!$to) {
                        return null;
                    }
                    $automaticValue['@id'] = $mapper
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

                    $val = $automaticValue['@value'];
                    $to = "$propertyTerm ^^$dataType ~ $val";
                    $to = $fieldNameToProperty($to);
                    if (!$to) {
                        return null;
                    }
                    $automaticValue['@value'] = $mapper
                        ->setMapping([])
                        ->setIsSimpleExtract(false)
                        ->setIsInternalSource(true)
                        ->extractValueOnly($resource, ['from' => '~', 'to' => $to]);

                    $check = array_intersect_key($automaticValueArray, ['type' => null, '@value' => null]);
                    break;
            }
        } else {
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
                    // Check the value.
                    $automaticValueTransformed = (int) $automaticValueTransformed;
                    try {
                        $api->read('resources', ['id' => $automaticValueTransformed], ['initialize' => false, 'finalize' => false]);
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
        }

        // Check if the value is already set on the main value data.

        // Avoid false different strings.
        $fixValue = fn ($value) => is_string($value) ? trim($value) : $value;
        $check = array_map($fixValue, $check);
        ksort($check);

        foreach ($resource[$propertyTerm] ?? [] as $value) {
            $checkValue = array_intersect_key($value, $check);
            if (isset($checkValue['value_resource_id'])) {
                $checkValue['value_resource_id'] = (int) $checkValue['value_resource_id'];
            }
            $checkValue = array_map($fixValue, $checkValue);
            ksort($checkValue);
            if ($check === $checkValue) {
                return null;
            }
        }

        // The value does not exist, so return it.
        return ['property_id' => $propertyId]
            + $automaticValueArray
            + ['is_public' => $isPublic];
    }

    protected function orderByLinkedResourcePropertyData(
        \AdvancedResourceTemplate\Api\Representation\ResourceTemplatePropertyDataRepresentation $rtpData,
        array $resource
    ): array {
        $orderByLinkedResourceProperties = $rtpData->dataValue('order_by_linked_resource_properties');
        if (!$orderByLinkedResourceProperties) {
            return $resource;
        }

        $propertyTerm = $rtpData->property()->term();
        if (!isset($resource[$propertyTerm]) || count($resource[$propertyTerm]) < 2) {
            return $resource;
        }

        /** @var \Omeka\Api\Manager $api */
        $api = $this->services->get('Omeka\ApiManager');

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
            foreach ($orderByLinkedResourceProperties as $propertyTerm => $order) {
                $order = strtolower($order) === 'desc' ? -1 : 1;
                $aResource = $api->read('resources', $aId)->getContent();
                $bResource = $api->read('resources', $bId)->getContent();
                $aVal = (string) $aResource->value($propertyTerm);
                $bVal = (string) $bResource->value($propertyTerm);
                if (!strlen($aVal) && !strlen($bVal)) {
                    // Do nothing with this term.
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
     * Check if a value is true (true, 1, "1", "true", yes", "on").
     *
     * This function avoids issues with values stored directly or with a form.
     * A value can be neither true or false.
     */
    protected function valueIsTrue($value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'yes', 'on'], true);
    }

    /**
     * Check if a value is false (false, 0, "0", "false", "no", "off").
     *
     * This function avoids issues with values stored directly or with a form.
     * A value can be neither true or false.
     */
    protected function valueIsFalse($value): bool
    {
        return in_array($value, [false, 0, '0', 'false', 'no', 'off'], true);
    }

    protected function getServiceLocator(): ServiceLocatorInterface
    {
        return $this->services;
    }
}

<?php declare(strict_types=1);

namespace AdvancedResourceTemplate\Listener;

use AdvancedResourceTemplate\Api\Representation\ResourceTemplatePropertyDataRepresentation;
use AdvancedResourceTemplate\Api\Representation\ResourceTemplateRepresentation;
use AdvancedResourceTemplate\Stdlib\ArtTrait;
use Common\Stdlib\EasyMeta;
use Common\Stdlib\PsrMessage;
use Doctrine\ORM\EntityManager;
use Exception;
use Laminas\EventManager\Event;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Api\Adapter\AbstractResourceEntityAdapter;
use Omeka\Api\Manager as ApiManager;
use Omeka\Entity\Resource;
use Omeka\Entity\ResourceTemplate;
use Omeka\Entity\Value;
use Omeka\Mvc\Status;
use Omeka\Settings\Settings;

/**
 * Listener for resource save events.
 *
 * Handles template settings, automatic values, and validation on resource save.
 */
class ResourceOnSave
{
    use ArtTrait;

    /**
     * @var \Omeka\Api\Manager
     */
    protected $api;

    /**
     * @var \Common\Stdlib\EasyMeta
     */
    protected $easyMeta;

    /**
     * @var \Doctrine\ORM\EntityManager
     */
    protected $entityManager;

    /**
     * @var \Omeka\Settings\Settings
     */
    protected $settings;

    /**
     * @var \Omeka\Mvc\Status
     */
    protected $status;

    /**
     * @var \AdvancedResourceTemplate\Listener\ResourceValidator
     */
    protected $validator;

    /**
     * @var \AdvancedResourceTemplate\Listener\AutomaticValuesHandler
     */
    protected $automaticValuesHandler;

    /**
     * @var \Laminas\ServiceManager\ServiceLocatorInterface
     */
    protected $services;

    public function __construct(
        ApiManager $api,
        EasyMeta $easyMeta,
        EntityManager $entityManager,
        Settings $settings,
        Status $status,
        ResourceValidator $validator,
        AutomaticValuesHandler $automaticValuesHandler,
        ServiceLocatorInterface $services
    ) {
        $this->api = $api;
        $this->easyMeta = $easyMeta;
        $this->entityManager = $entityManager;
        $this->settings = $settings;
        $this->status = $status;
        $this->validator = $validator;
        $this->automaticValuesHandler = $automaticValuesHandler;
        $this->services = $services;
    }

    /**
     * Handle template settings on resource save (api.hydrate.pre).
     */
    public function handleTemplateSettingsOnSave(Event $event): void
    {
        /** @var \Omeka\Api\Request $request */
        $request = $event->getParam('request');
        $resource = $request->getContent();

        $template = $this->getTemplateFromResource($resource);
        if (!$template) {
            return;
        }

        $vaTemplateDefault = $this->getValueAnnotationTemplate($template);

        $type = $request->getResource() ?: $resource['@type'] ?? null;
        $isItem = in_array($type, ['o:Item', 'items'])
            || (is_array($type) && in_array('o:Item', $type));

        // Template level.
        if ($isItem) {
            $resource = $this->automaticValuesHandler->appendAutomaticItemSets($template, $resource);
        }
        $resource = $this->automaticValuesHandler->appendAutomaticValuesFromTemplateData($template, $resource);

        // Property level.
        foreach ($template->resourceTemplateProperties() as $templateProperty) {
            foreach ($templateProperty->data() as $rtpData) {
                $resource = $this->automaticValuesHandler->explodeValueFromTemplatePropertyData($rtpData, $resource);

                $automaticValues = $this->automaticValuesHandler->automaticValuesFromTemplatePropertyData($rtpData, $resource);
                foreach ($automaticValues as $automaticValue) {
                    $resource[$templateProperty->property()->term()][] = $automaticValue;
                }

                $resource = $this->automaticValuesHandler->orderByLinkedResourcePropertyData($rtpData, $resource);
                $resource = $this->handleVaTemplateSettings($resource, $rtpData, $vaTemplateDefault);
            }
        }

        $request->setContent($resource);
    }

    /**
     * Validate entity after hydration (api.hydrate.post).
     */
    public function validateEntityHydratePost(Event $event): void
    {
        /** @var \Omeka\Entity\Resource $entity */
        $entity = $event->getParam('entity');
        $templateEntity = $entity->getResourceTemplate();

        if (!$templateEntity) {
            return;
        }

        /** @var \Omeka\Api\Adapter\AbstractResourceEntityAdapter $adapter */
        $adapter = $event->getTarget();
        $template = $adapter->getAdapter('resource_templates')->getRepresentation($templateEntity);
        $request = $event->getParam('request');
        $errorStore = $event->getParam('errorStore');

        // Update the title with fallback properties.
        $title = $entity->getTitle();
        if ($title === null || $title === '') {
            $this->fillTitleFromFallbackProperties($entity, $adapter);
        }

        // Update open custom vocabs in any cases.
        $this->updateCustomVocabOpen($event);

        // Check the template constraints.
        if ($request->getOption('skipValidation')) {
            return;
        }

        $skipChecks = (bool) $this->settings->get('advancedresourcetemplate_skip_checks');
        if ($skipChecks) {
            return;
        }

        $directMessage = $this->shouldDisplayDirectMessage();
        if ($directMessage) {
            $messenger = $this->services->get('ControllerPluginManager')->get('messenger');
            $this->validator->setMessenger($messenger);
        }

        // Validate template level constraints.
        $this->validateTemplateConstraints($entity, $template, $errorStore, $directMessage);

        // Validate property level constraints.
        $resource = $adapter->getRepresentation($entity);
        $this->validator->validateProperties($resource, $errorStore, $directMessage);
    }

    /**
     * Store value annotation templates after resource creation/update.
     */
    public function storeVaTemplates(Event $event): void
    {
        /** @var \Omeka\Api\Response $response */
        $response = $event->getParam('response');
        $resource = $response->getContent('resource');
        $template = $resource->getResourceTemplate();

        if (!$template) {
            return;
        }

        $vaDefaultTemplate = null;
        $rtData = $this->entityManager
            ->getRepository(\AdvancedResourceTemplate\Entity\ResourceTemplateData::class)
            ->findOneBy(['resourceTemplate' => $template->getId()]);

        if ($rtData) {
            $vaDefaultTemplateId = (int) $rtData->getDataValue('value_annotations_template');
            if ($vaDefaultTemplateId) {
                $vaDefaultTemplate = $this->entityManager->find(ResourceTemplate::class, $vaDefaultTemplateId);
            }
        }

        foreach ($resource->getValues() as $value) {
            $valueAnnotation = $value->getValueAnnotation();
            if ($valueAnnotation) {
                $vaTemplate = $this->getVaTemplate($value, $template, $vaDefaultTemplate);
                if ($vaTemplate) {
                    $valueAnnotation->setResourceTemplate($vaTemplate);
                    $valueAnnotation->setResourceClass($vaTemplate->getResourceClass());
                } else {
                    $valueAnnotation->setResourceTemplate(null);
                    $valueAnnotation->setResourceClass(null);
                }
            }
        }

        $this->entityManager->flush();
    }

    /**
     * Get template from resource data.
     */
    protected function getTemplateFromResource(array $resource): ?ResourceTemplateRepresentation
    {
        $template = $resource['o:resource_template'] ?? null;
        if (!$template) {
            return null;
        }

        $templateId = is_object($template) ? $template->id() : $template['o:id'] ?? null;
        if (!$templateId) {
            return null;
        }

        try {
            return $this->api->read('resource_templates', ['id' => $templateId])->getContent();
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get value annotation template from main template.
     */
    protected function getValueAnnotationTemplate(ResourceTemplateRepresentation $template): ?ResourceTemplateRepresentation
    {
        $vaTemplateDefaultId = $template->dataValue('value_annotations_template');
        if (!is_numeric($vaTemplateDefaultId)) {
            return null;
        }

        try {
            return $this->api->read('resource_templates', ['id' => $vaTemplateDefaultId])->getContent();
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Handle value annotation template settings.
     */
    protected function handleVaTemplateSettings(
        array $resource,
        ResourceTemplatePropertyDataRepresentation $rtpData,
        ?ResourceTemplateRepresentation $vaTemplateDefault
    ): array {
        $propertyTerm = $rtpData->property()->term();
        if (empty($resource[$propertyTerm])) {
            return $resource;
        }

        $vaTemplate = $this->resolveVaTemplate($rtpData, $vaTemplateDefault);
        if (!$vaTemplate) {
            return $resource;
        }

        foreach ($resource[$propertyTerm] as $index => $value) {
            $vaResource = $value['@annotation'] ?? [];
            $vaResource = $this->automaticValuesHandler->appendAutomaticValuesFromTemplateData($vaTemplate, $vaResource);

            foreach ($vaTemplate->resourceTemplateProperties() as $vaTemplateProperty) {
                foreach ($vaTemplateProperty->data() as $vaRtpData) {
                    $vaResource = $this->automaticValuesHandler->explodeValueFromTemplatePropertyData($vaRtpData, $vaResource);

                    $automaticValues = $this->automaticValuesHandler->automaticValuesFromTemplatePropertyData($vaRtpData, $vaResource);
                    foreach ($automaticValues as $automaticValue) {
                        $vaResource[$vaTemplateProperty->property()->term()][] = $automaticValue;
                    }

                    $vaResource = $this->automaticValuesHandler->orderByLinkedResourcePropertyData($vaRtpData, $vaResource);
                }
            }

            $resource[$propertyTerm][$index]['@annotation'] = $vaResource;
        }

        return $resource;
    }

    /**
     * Resolve value annotation template for a property.
     */
    protected function resolveVaTemplate(
        ResourceTemplatePropertyDataRepresentation $rtpData,
        ?ResourceTemplateRepresentation $vaTemplateDefault
    ): ?ResourceTemplateRepresentation {
        $vaTemplateDefaultId = $vaTemplateDefault ? $vaTemplateDefault->id() : null;
        $vaTemplateId = $rtpData->dataValue('value_annotations_template');

        if (empty($vaTemplateId) || (int) $vaTemplateId === $vaTemplateDefaultId) {
            return $vaTemplateDefault;
        }

        if (is_numeric($vaTemplateId)) {
            try {
                return $this->api->read('resource_templates', ['id' => $vaTemplateId])->getContent();
            } catch (Exception $e) {
                // Fall through.
            }
        }

        return null;
    }

    /**
     * Validate template level constraints.
     */
    protected function validateTemplateConstraints(
        Resource $entity,
        ResourceTemplateRepresentation $template,
        $errorStore,
        bool $directMessage
    ): void {
        $messenger = $directMessage
            ? $this->services->get('ControllerPluginManager')->get('messenger')
            : null;

        // Check use_for_resources.
        $useForResources = $template->dataValue('use_for_resources') ?: [];
        $resourceName = $entity->getResourceName();
        if ($useForResources && !in_array($resourceName, $useForResources)) {
            $message = new PsrMessage('This template cannot be used for this resource.'); // @translate
            $errorStore->addError('o:resource_template[o:id]', $message);
            if ($directMessage && $messenger) {
                $messenger->addError($message);
            }
        }

        // Check require_resource_class.
        $resourceClass = $entity->getResourceClass();
        if ($this->valueIsTrue($template->dataValue('require_resource_class')) && !$resourceClass) {
            $message = new PsrMessage('A class is required.'); // @translate
            $errorStore->addError('o:resource_class[o:id]', $message);
        }

        // Check closed_class_list.
        if ($this->valueIsTrue($template->dataValue('closed_class_list')) && $resourceClass) {
            $suggestedClasses = $template->dataValue('suggested_resource_class_ids') ?: [];
            if ($suggestedClasses && !in_array($resourceClass->getId(), $suggestedClasses)) {
                $message = count($suggestedClasses) === 1
                    ? new PsrMessage('The class should be {resource_class}.', ['resource_class' => key($suggestedClasses)])
                    : new PsrMessage('The class should be one of {resource_classes}.', ['resource_classes' => implode(', ', array_keys($suggestedClasses))]);
                $errorStore->addError('o:resource_class[o:id]', $message);
            }
        }

        // Check media_templates_minimum for items.
        if ($entity instanceof \Omeka\Entity\Item) {
            $this->validateMediaRequirements($entity, $template, $errorStore);
        }
    }

    /**
     * Validate media requirements for items.
     */
    protected function validateMediaRequirements(
        \Omeka\Entity\Item $entity,
        ResourceTemplateRepresentation $template,
        $errorStore
    ): void {
        $requireMedias = array_filter($template->dataValue('media_templates_minimum') ?? []);
        if (!$requireMedias) {
            return;
        }

        $medias = $entity->getMedia();
        if (count($medias) < array_sum($requireMedias)) {
            $message = new PsrMessage(
                'The minimum number of files or medias is {min}.', // @translate
                ['min' => array_sum($requireMedias)]
            );
            $errorStore->addError('o:media', $message);
            return;
        }

        $countMediasByTemplate = [];
        foreach ($medias as $media) {
            $mediaTemplate = $media->getResourceTemplate();
            $templateId = $mediaTemplate ? $mediaTemplate->getId() : 0;
            $templateLabel = $mediaTemplate ? $mediaTemplate->getLabel() : '';
            $countMediasByTemplate[$templateId] = ($countMediasByTemplate[$templateId] ?? 0) + 1;
            $countMediasByTemplate[$templateLabel] = ($countMediasByTemplate[$templateLabel] ?? 0) + 1;
        }

        foreach ($requireMedias as $mediaTemplateIdOrLabel => $min) {
            if (!isset($countMediasByTemplate[$mediaTemplateIdOrLabel])
                || $countMediasByTemplate[$mediaTemplateIdOrLabel] < $min
            ) {
                $message = new PsrMessage(
                    'The minimum number of files or medias is {min}.', // @translate
                    ['min' => array_sum($requireMedias)]
                );
                $errorStore->addError('o:media', $message);
                break;
            }
        }
    }

    /**
     * Fill title from fallback properties.
     */
    protected function fillTitleFromFallbackProperties(Resource $entity, AbstractResourceEntityAdapter $adapter): void
    {
        $resource = $adapter->getRepresentation($entity);
        $resourceTemplate = $resource->resourceTemplate();
        $titleProperty = $resourceTemplate->titleProperty();

        $defaultFallbacks = [$titleProperty ? $titleProperty->term() : 'dcterms:title'];
        $fallbacks = array_merge(
            $defaultFallbacks,
            array_values($resourceTemplate->dataValue('title_fallback_properties') ?: [])
        );

        foreach ($fallbacks as $fallback) {
            foreach ($resource->value($fallback, ['all' => true]) as $value) {
                $val = ($vr = $value->valueResource())
                    ? $this->entityManager->find(Resource::class, $vr->id())->getTitle()
                    : $value->value();
                if ($val !== null && $val !== '') {
                    $entity->setTitle($val);
                    return;
                }
            }
        }
    }

    /**
     * Update open custom vocabs with new terms.
     */
    protected function updateCustomVocabOpen(Event $event): void
    {
        /** @var \Omeka\Entity\Resource $entity */
        $entity = $event->getParam('entity');
        $templateEntity = $entity->getResourceTemplate();

        if (!$templateEntity) {
            return;
        }

        $adapter = $event->getTarget();
        $template = $adapter->getAdapter('resource_templates')->getRepresentation($templateEntity);
        $resource = $adapter->getRepresentation($entity);

        // Quick check for open custom vocabs.
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

        $this->processCustomVocabUpdates($template, $resource, $event->getParam('errorStore'));
    }

    /**
     * Process custom vocab updates.
     */
    protected function processCustomVocabUpdates(
        ResourceTemplateRepresentation $template,
        $resource,
        $errorStore
    ): void {
        $customVocabs = [];

        foreach ($this->api->search('custom_vocabs')->getContent() as $customVocab) {
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

        $this->applyCustomVocabUpdates($customVocabs, $errorStore);
    }

    /**
     * Apply custom vocab updates.
     */
    protected function applyCustomVocabUpdates(array $customVocabs, $errorStore): void
    {
        $acl = $this->services->get('Omeka\Acl');
        $roles = $acl->getRoles();
        $acl->allow($roles, [\CustomVocab\Api\Adapter\CustomVocabAdapter::class], ['update']);

        $directMessage = $this->shouldDisplayDirectMessage();
        $messenger = $directMessage ? $this->services->get('ControllerPluginManager')->get('messenger') : null;

        foreach ($customVocabs as $customVocab) {
            if (!$customVocab['new']) {
                continue;
            }

            $newTerms = array_merge($customVocab['terms'], $customVocab['new']);
            try {
                $this->api->update('custom_vocabs', $customVocab['id'], ['o:terms' => $newTerms], [], ['isPartial' => true]);
                if ($directMessage && $messenger) {
                    $message = count($customVocab['new']) <= 1
                        ? new PsrMessage('New descriptor appended to custom vocab "{custom_vocab}": {value}.', ['custom_vocab' => $customVocab['label'], 'value' => $customVocab['new']])
                        : new PsrMessage('New descriptors appended to custom vocab "{custom_vocab}": {values}.', ['custom_vocab' => $customVocab['label'], 'values' => implode('", "', $customVocab['new'])]);
                    $messenger->addSuccess($message);
                }
            } catch (Exception $e) {
                $message = new PsrMessage(
                    'Unable to append new descriptors to custom vocab "{custom_vocab}": {error}', // @translate
                    ['custom_vocab' => $customVocab['label'], 'error' => $e->getMessage()]
                );
                $errorStore->addError($customVocab['term'], $message);
                if ($directMessage && $messenger) {
                    $messenger->addError($message);
                }
            }
        }
    }

    /**
     * Get value annotation template for a value.
     */
    protected function getVaTemplate(
        Value $value,
        ResourceTemplate $template,
        ?ResourceTemplate $vaDefaultTemplate
    ): ?ResourceTemplate {
        $vaTemplateOption = null;
        $property = $value->getProperty();

        $rtp = $this->entityManager
            ->getRepository(\Omeka\Entity\ResourceTemplateProperty::class)
            ->findOneBy([
                'resourceTemplate' => $template->getId(),
                'property' => $property->getId(),
            ]);

        if ($rtp) {
            $rtpData = $this->entityManager
                ->getRepository(\AdvancedResourceTemplate\Entity\ResourceTemplatePropertyData::class)
                ->findOneBy([
                    'resourceTemplate' => $template->getId(),
                    'resourceTemplateProperty' => $rtp->getId(),
                ], ['id' => 'ASC']);

            if ($rtpData) {
                $vaTemplateOption = $rtpData->getDataValue('value_annotations_template');
                if (is_numeric($vaTemplateOption)) {
                    $vaTemplate = $this->entityManager->find(ResourceTemplate::class, (int) $vaTemplateOption);
                    if ($vaTemplate) {
                        return $vaTemplate;
                    }
                }
            }
        }

        return empty($vaTemplateOption) ? $vaDefaultTemplate : null;
    }

    /**
     * Check if messages should be displayed directly to the user.
     */
    protected function shouldDisplayDirectMessage(): bool
    {
        $routeMatch = $this->status->getRouteMatch();
        $routeName = $routeMatch ? $routeMatch->getMatchedRouteName() : null;

        return $routeName === 'admin/default'
            && in_array($routeMatch->getParam('__CONTROLLER__'), ['item', 'item-set', 'media', 'annotation'])
            && in_array($routeMatch->getParam('action'), ['add', 'edit']);
    }
}

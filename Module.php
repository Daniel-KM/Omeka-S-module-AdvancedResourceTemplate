<?php declare(strict_types=1);

namespace AdvancedResourceTemplate;

if (!class_exists(\Common\TraitModule::class)) {
    require_once dirname(__DIR__) . '/Common/TraitModule.php';
}

use AdvancedResourceTemplate\Listener\ResourceOnSave;
use Common\TraitModule;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\MvcEvent;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Module\AbstractModule;
use Omeka\Mvc\Status;

/**
 * Advanced Resource Template.
 *
 * @copyright Daniel Berthereau, 2020-2025
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */
class Module extends AbstractModule
{
    use TraitModule;

    const NAMESPACE = __NAMESPACE__;

    protected function preInstall(): void
    {
        $services = $this->getServiceLocator();
        $plugins = $services->get('ControllerPluginManager');
        $translate = $plugins->get('translate');

        if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.65')) {
            $message = new \Omeka\Stdlib\Message(
                $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
                'Common', '3.4.65'
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
        }

        if ($this->isModuleActive('DynamicItemSets')
            && !$this->isModuleVersionAtLeast('DynamicItemSets', '3.4.2')
        ) {
            $message = new \Common\Stdlib\PsrMessage(
                $translate('Some features require the module {module} to be upgraded to version {version} or later.'), // @translate
                ['module' => 'Dynamic Item Sets', 'version' => '3.4.2']
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
        }

        if ($this->isModuleActive('AdvancedSearch')
            && !$this->isModuleVersionAtLeast('AdvancedSearch', '3.4.22')
        ) {
            $message = new \Common\Stdlib\PsrMessage(
                $translate('Some features require the module {module} to be upgraded to version {version} or later.'), // @translate
                ['module' => 'Advanced Search', 'version' => '3.4.22']
            );
            $messenger = $services->get('ControllerPluginManager')->get('messenger');
            $messenger->addWarning($message);
        }
    }

    protected function postInstall(): void
    {
        $filepath = __DIR__ . '/data/mapping/mappings.ini';
        if (!file_exists($filepath) || is_file($filepath) || !is_readable($filepath)) {
            return;
        }
        $mapping = $this->stringToAutofillers(file_get_contents($filepath));
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $settings->set('advancedresourcetemplate_autofillers', $mapping);
    }

    public function onBootstrap(MvcEvent $event): void
    {
        parent::onBootstrap($event);

        // Copy or rights of the main Resource Template.
        /** @var \Omeka\Permissions\Acl $acl */
        $acl = $this->getServiceLocator()->get('Omeka\Acl');
        $roles = $acl->getRoles();
        $acl
            ->allow(
                null,
                [\AdvancedResourceTemplate\Api\Adapter\ResourceTemplateAdapter::class],
                ['search', 'read']
            )
            ->allow(
                ['author', 'editor'],
                [\AdvancedResourceTemplate\Api\Adapter\ResourceTemplateAdapter::class],
                ['create', 'update', 'delete']
            )
            ->allow(
                null,
                [
                    \AdvancedResourceTemplate\Entity\ResourceTemplateData::class,
                    \AdvancedResourceTemplate\Entity\ResourceTemplatePropertyData::class,
                ],
                ['read']
            )
            ->allow(
                ['author', 'editor'],
                [
                    \AdvancedResourceTemplate\Entity\ResourceTemplateData::class,
                    \AdvancedResourceTemplate\Entity\ResourceTemplatePropertyData::class,
                ],
                ['create', 'update', 'delete']
            )
            ->allow(
                $roles,
                ['AdvancedResourceTemplate\Controller\Admin\Index']
            )
        ;
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        // Store some template settings in main settings for simple access.
        $sharedEventManager->attach(
            \AdvancedResourceTemplate\Api\Adapter\ResourceTemplateAdapter::class,
            'api.create.post',
            [$this, 'handleTemplateConfigOnSave']
        );
        $sharedEventManager->attach(
            \AdvancedResourceTemplate\Api\Adapter\ResourceTemplateAdapter::class,
            'api.update.post',
            [$this, 'handleTemplateConfigOnSave']
        );
        $sharedEventManager->attach(
            \AdvancedResourceTemplate\Api\Adapter\ResourceTemplateAdapter::class,
            'api.delete.post',
            [$this, 'handleTemplateConfigOnSave']
        );

        // Manage some settings (auto-value, exploding, order, etc.) for each
        // resource type.
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.create.pre',
            [$this, 'handleTemplateSettingsOnSave']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.update.pre',
            [$this, 'handleTemplateSettingsOnSave']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\MediaAdapter::class,
            'api.create.pre',
            [$this, 'handleTemplateSettingsOnSave']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\MediaAdapter::class,
            'api.update.pre',
            [$this, 'handleTemplateSettingsOnSave']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemSetAdapter::class,
            'api.create.pre',
            [$this, 'handleTemplateSettingsOnSave']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemSetAdapter::class,
            'api.update.pre',
            [$this, 'handleTemplateSettingsOnSave']
        );
        $sharedEventManager->attach(
            \Annotate\Api\Adapter\AnnotationAdapter::class,
            'api.create.pre',
            [$this, 'handleTemplateSettingsOnSave']
        );
        $sharedEventManager->attach(
            \Annotate\Api\Adapter\AnnotationAdapter::class,
            'api.update.pre',
            [$this, 'handleTemplateSettingsOnSave']
        );

        // Check the resource according to the specified template settings.
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.hydrate.post',
            [$this, 'validateEntityHydratePost']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\MediaAdapter::class,
            'api.hydrate.post',
            [$this, 'validateEntityHydratePost']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemSetAdapter::class,
            'api.hydrate.post',
            [$this, 'validateEntityHydratePost']
        );
        $sharedEventManager->attach(
            \Annotate\Api\Adapter\AnnotationAdapter::class,
            'api.hydrate.post',
            [$this, 'validateEntityHydratePost']
        );

        // Store the template and the class of the value annotation.
        // Ideally, use api.hydrate.pre on value annotation.
        // But it is complex to get the main value and the resource from the
        // annotation during a creation, so use post for it.
        // Nevertheless, with hydrate post for value annotation, the value may
        // be not yet stored, so not yet findable.
        // The issue is the same for the value: a new value has no id as long as
        // long as the resource is not stored.
        // And the issue is the same for resource during a bulk process.
        // So it is not possible to use hydrate post, so use api.create.post and
        // api.update.post on each resource.
        /*
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ValueAnnotationAdapter::class,
            'api.hydrate.post',
            [$this, 'hydrateValueAnnotationPost']
        );
        */
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.create.post',
            [$this, 'storeVaTemplates']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.update.post',
            [$this, 'storeVaTemplates']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\MediaAdapter::class,
            'api.create.post',
            [$this, 'storeVaTemplates']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\MediaAdapter::class,
            'api.update.post',
            [$this, 'storeVaTemplates']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemSetAdapter::class,
            'api.create.post',
            [$this, 'storeVaTemplates']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemSetAdapter::class,
            'api.update.post',
            [$this, 'storeVaTemplates']
        );
        $sharedEventManager->attach(
            \Annotate\Api\Adapter\AnnotationAdapter::class,
            'api.create.post',
            [$this, 'storeVaTemplates']
        );
        $sharedEventManager->attach(
            \Annotate\Api\Adapter\AnnotationAdapter::class,
            'api.update.post',
            [$this, 'storeVaTemplates']
        );

        // Display values according to options of the resource template.
        // For compatibility with other modules (HideProperties, Internationalisation)
        // that use the term as key in the list of displayed values, the event
        // should be triggered lastly.
        // Anyway, this is now an iterator that keeps the same key for multiple
        // values.
        $sharedEventManager->attach(
            \Omeka\Api\Representation\ItemRepresentation::class,
            'rep.resource.display_values',
            [$this, 'handleResourceDisplayValues'],
            -100
        );
        $sharedEventManager->attach(
            \Omeka\Api\Representation\MediaRepresentation::class,
            'rep.resource.display_values',
            [$this, 'handleResourceDisplayValues'],
            -100
        );
        $sharedEventManager->attach(
            \Omeka\Api\Representation\ItemSetRepresentation::class,
            'rep.resource.display_values',
            [$this, 'handleResourceDisplayValues'],
            -100
        );
        $sharedEventManager->attach(
            \Annotate\Api\Representation\AnnotationRepresentation::class,
            'rep.resource.display_values',
            [$this, 'handleResourceDisplayValues'],
            -100
        );
        // Handle value annotations like values, since they may have a template
        // and all display settings are managed with it.
        $sharedEventManager->attach(
            \Omeka\Api\Representation\ValueAnnotationRepresentation::class,
            'rep.resource.value_annotation_display_values',
            [$this, 'handleResourceDisplayValues'],
            -100
        );

        // Display subject values according to options of the resource template.
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.subject_values.query',
            [$this, 'handleResourceDisplaySubjectValues'],
            -100
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.subject_values_simple.query',
            [$this, 'handleResourceDisplaySubjectValues'],
            -100
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\MediaAdapter::class,
            'api.subject_values.query',
            [$this, 'handleResourceDisplaySubjectValues'],
            -100
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\MediaAdapter::class,
            'api.subject_values_simple.query',
            [$this, 'handleResourceDisplaySubjectValues'],
            -100
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemSetAdapter::class,
            'api.subject_values.query',
            [$this, 'handleResourceDisplaySubjectValues'],
            -100
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemSetAdapter::class,
            'api.subject_values_simple.query',
            [$this, 'handleResourceDisplaySubjectValues'],
            -100
        );
        $sharedEventManager->attach(
            \Annotate\Api\Adapter\AnnotationAdapter::class,
            'api.subject_values.query',
            [$this, 'handleResourceDisplaySubjectValues'],
            -100
        );
        $sharedEventManager->attach(
            \Annotate\Api\Adapter\AnnotationAdapter::class,
            'api.subject_values_simple.query',
            [$this, 'handleResourceDisplaySubjectValues'],
            -100
        );

        // Display some property values with a search link or icons.
        $sharedEventManager->attach(
            \Omeka\Api\Representation\ValueRepresentation::class,
            'rep.value.html',
            [$this, 'handleRepresentationValueHtml']
        );

        // Display some property values with a search link or icons in
        // resource/show only.
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            'view.show.value',
            [$this, 'handleViewResourceShowValue']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Media',
            'view.show.value',
            [$this, 'handleViewResourceShowValue']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\ItemSet',
            'view.show.value',
            [$this, 'handleViewResourceShowValue']
        );
        $sharedEventManager->attach(
            'Annotate\Controller\Admin\AnnotationController',
            'view.show.value',
            [$this, 'handleViewResourceShowValue']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Site\Item',
            'view.show.value',
            [$this, 'handleViewResourceShowValue']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Site\Media',
            'view.show.value',
            [$this, 'handleViewResourceShowValue']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Site\ItemSet',
            'view.show.value',
            [$this, 'handleViewResourceShowValue']
        );
        $sharedEventManager->attach(
            'Annotate\Controller\Site\AnnotationController',
            'view.show.value',
            [$this, 'handleViewResourceShowValue']
        );

        // Add css/js to some admin pages.
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            'view.layout',
            [$this, 'addAdminResourceHeaders']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\ItemSet',
            'view.layout',
            [$this, 'addAdminResourceHeaders']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Media',
            'view.layout',
            [$this, 'addAdminResourceHeaders']
        );
        // For simplicity, some modules that use resource form are added here.
        $sharedEventManager->attach(
            \Annotate\Controller\Admin\AnnotationController::class,
            'view.layout',
            [$this, 'addAdminResourceHeaders']
        );

        // Modify the resource form for templates or set one by default.
        $sharedEventManager->attach(
            \Omeka\Form\ResourceForm::class,
            'form.add_elements',
            [$this, 'handleResourceForm']
        );

        // Append settings and site settings.
        $sharedEventManager->attach(
            \Omeka\Form\SettingForm::class,
            'form.add_elements',
            [$this, 'handleMainSettings']
        );
        $sharedEventManager->attach(
            \Omeka\Form\SettingForm::class,
            'form.add_input_filters',
            [$this, 'handleMainSettingsFilters']
        );
        $sharedEventManager->attach(
            \Omeka\Form\SiteSettingsForm::class,
            'form.add_elements',
            [$this, 'handleSiteSettings']
        );

        // Modify display of resource template to add a button.
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\ResourceTemplate',
            'view.layout',
            [$this, 'handleViewLayoutResourceTemplate']
        );
        // Modify display of resource template to add a button for new resource.
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\ResourceTemplate',
            'view.browse.actions',
            [$this, 'appendButtonActionNewResource']
        );

        // Add elements to the resource template form.
        $sharedEventManager->attach(
            // \Omeka\Form\ResourceTemplateForm::class,
            \AdvancedResourceTemplate\Form\ResourceTemplateForm::class,
            'form.add_elements',
            [$this, 'addResourceTemplateFormElements']
        );
        $sharedEventManager->attach(
            // \Omeka\Form\ResourceTemplatePropertyFieldset::class,
            \AdvancedResourceTemplate\Form\ResourceTemplatePropertyFieldset::class,
            'form.add_elements',
            [$this, 'addResourceTemplatePropertyFieldsetElements']
        );
    }

    public function handleTemplateConfigOnSave(Event $event): void
    {
        $this->storeResourceTemplateSettings();
    }

    public function handleTemplateSettingsOnSave(Event $event): void
    {
        $resourceOnSave = new ResourceOnSave($this->getServiceLocator());
        $resourceOnSave->handleTemplateSettingsOnSave($event);
    }

    public function validateEntityHydratePost(Event $event): void
    {
        $resourceOnSave = new ResourceOnSave($this->getServiceLocator());
        $resourceOnSave->validateEntityHydratePost($event);
    }

    public function storeVaTemplates(Event $event): void
    {
        $resourceOnSave = new ResourceOnSave($this->getServiceLocator());
        $resourceOnSave->storeVaTemplates($event);
    }

    /**
     * Prepare specific data to display the list of the resource values data.
     *
     * Specific data passed to display values for this module are:
     * - groups of properties, managed in overridden view template resource-values
     * - term, like the key
     * - duplicated properties with a specific label and comments
     *
     * Some of the complexity of the module is needed to kept compatibility
     * with core, even if the module is removed.
     *
     * @see \Omeka\Api\Representation\AbstractResourceEntityRepresentation::displayValues()
     */
    public function handleResourceDisplayValues(Event $event): void
    {
        /**
         * @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource
         * @var \AdvancedResourceTemplate\Api\Representation\ResourceTemplateRepresentation $template
         * @var \AdvancedResourceTemplate\Api\Representation\ResourceTemplatePropertyRepresentation[] $templateProperties
         * @var array $values
         * @var array $groups
         */
        $values = $event->getParam('values');
        if (!count($values)) {
            return;
        }

        $services = $this->getServiceLocator();
        $status = $services->get('Omeka\Status');
        if ($status->isSiteRequest()
            && $services->get('Omeka\Settings')->get('advancedresourcetemplate_skip_private_values')
        ) {
            $values = $this->hidePrivateValues($values);
            if (!count($values)) {
                return;
            }
        }

        $resource = $event->getTarget();
        $template = $resource->resourceTemplate();

        if ($template) {
            $groups = $template->dataValue('groups') ?: [];
            $templateProperties = $template->resourceTemplateProperties();
        } else {
            $groups = [];
            $templateProperties = [];
        }

        $newValues = count($templateProperties)
            ? $this->prepareGroupsValues($resource, $templateProperties, $values, $groups)
            : $this->prependGroupsToValues($resource, $values, $groups);

        $event->setParam('values', $newValues);
    }

    protected function hidePrivateValues(array $values): array
    {
        foreach ($values as $term => &$propertyData) {
            /** @var \Omeka\Api\Representation\ValueRepresentation $value */
            foreach ($propertyData['values'] as $key => $value) {
                if (!$value->isPublic()) {
                    unset($propertyData['values'][$key]);
                }
            }
            if (count($propertyData['values'])) {
                $propertyData['values'] = array_values($propertyData['values']);
            } else {
                unset($values[$term]);
            }
        }
        unset($propertyData);
        return $values;
    }

    /**
     * Prepare specific data to display the list of the linked resources.
     *
     * Specific data passed to display values for this module are:
     * - order of subject values
     *
     * @see \Omeka\Api\Adapter\AbstractResourceEntityAdapter::getSubjectValues()
     */
    public function handleResourceDisplaySubjectValues(Event $event): void
    {
        /**
         * @var \Omeka\Api\Adapter\AbstractResourceEntityAdapter $adapter
         * @var \Doctrine\ORM\QueryBuilder $qb
         * @var \Omeka\Entity\Resource $resource
         * @var int|string|null $propertyId
         * @var string|null $resourceType
         * @var int|null $siteId
         * @var \AdvancedResourceTemplate\Api\Representation\ResourceTemplateRepresentation $template
         * @var \Common\Stdlib\EasyMeta $easyMeta
         *
         * Warning: the property id may not be the property id, but the property
         * id and a resource template property id like "123-234".
         * @see \Omeka\Api\Adapter\AbstractResourceEntityAdapter::getSubjectValuesQueryBuilder()
         */
        $resource = $event->getParam('resource');
        $template = $resource->getResourceTemplate();
        if (!$template) {
            return;
        }

        $adapter = $event->getTarget();
        $templateAdapter = $adapter->getAdapter('resource_templates');
        $template = $templateAdapter->getRepresentation($template);

        // Use the template order for the property when propertyId is set.
        $order = $template->dataValue('subject_values_order');
        if (!$order) {
            return;
        }

        $services = $this->getServiceLocator();
        $easyMeta = $services->get('Common\EasyMeta');

        $propertyId = $event->getParam('propertyId');
        $propertyTerm = $propertyId
            ? $easyMeta->propertyTerm(strtok((string) $propertyId, '-'))
            : null;
        if (empty($order[$propertyTerm])) {
            return;
        }

        $order = $order[$propertyTerm];

        // Filter order early.
        $orderPropertyIds = $easyMeta->propertyIds(array_keys($order));
        $order = array_replace($orderPropertyIds, array_intersect_key($order, $orderPropertyIds));
        if (!$order) {
            return;
        }

        $qb = $event->getParam('queryBuilder');
        $qb
            // Default order without "resource.title".
            ->orderBy('property.id, resource_template_property.alternateLabel');

        foreach ($order as $property => $sort) {
            $property = $easyMeta->propertyId($property);
            if (!$property) {
                continue;
            }
            $alias = $adapter->createAlias();
            $aliasProperty = $adapter->createAlias();
            $sort = strtoupper((string) $sort) === 'DESC' ? 'DESC' : 'ASC';
            $qb
                ->leftJoin(\Omeka\Entity\Value::class, $alias, 'WITH', "$alias.resource = value.resource AND $alias.property = :$aliasProperty AND $alias.value IS NOT NULL")
                ->setParameter($aliasProperty, $property, \Doctrine\DBAL\ParameterType::INTEGER)
                ->addOrderBy("$alias.value", $sort);
        }
    }

    /**
     * Prepend keys "group" and "term" to display values.
     *
     * Manage the rare case where there is a template without property.
     *
     * Warning: Duplicate properties are not managed here.
     */
    protected function prependGroupsToValues(
        AbstractResourceEntityRepresentation $resource,
        array $values,
        array $groups
    ): array {
        if (!$groups) {
            foreach ($values as $term => &$propertyData) {
                $propertyData = [
                    'group' => null,
                    'term' => $term,
                ] + $propertyData;
            }
            unset($propertyData);
            return $values;
        }

        // Here, there is no duplicate labels,
        foreach ($values as $term => &$propertyData) {
            $currentGroup = null;
            foreach ($groups as $groupLabel => $termLabels) {
                if (in_array($term, $termLabels)) {
                    $currentGroup = $groupLabel;
                    break;
                }
            }
            $propertyData = [
                'group' => $currentGroup,
                'term' => $term,
            ] + $propertyData;
        }
        unset($propertyData);
        return $values;
    }

    /**
     * Prepare duplicate properties with specific labels and comments.
     *
     * In that case, convert the array into an IteratorIterator, so the key
     * (term) stays the same, but there are more values with it.
     *
     * In the previous version, the key "term" was modified as term + index,
     * and the label and comment were updated, so the default template "common/resource-values"
     * was wrapped and was able to display values as standard ones.
     *
     * @see \Omeka\Api\Representation\AbstractResourceEntityRepresentation::values()
     * @see \Omeka\Api\Representation\AbstractResourceEntityRepresentation::displayValues()
     */
    protected function prepareGroupsValues(
        AbstractResourceEntityRepresentation $resource,
        array $templateProperties,
        array $values,
        array $groups
    ): iterable {
        // The process should take care of values appended to a resource that
        // have a data type that is not specified in template properties, in
        // particular the default ones (literal, resource, uri). It may fix bad
        // imports too, or resources with a template that was updated later.

        $services = $this->getServiceLocator();
        $translator = $services->get('MvcTranslator');

        // TODO Check if this process can be simplified (three double loops, even if loops are small and for one resource a time).

        // The alternate comments are included too, even if they are not
        // displayed in the default resource template.

        // Check and prepare values when a property have multiple labels.
        $labelsAndComments = [];
        $hasMultipleLabels = false;
        /** @var \AdvancedResourceTemplate\Api\Representation\ResourceTemplatePropertyRepresentation $rtp */
        foreach ($templateProperties as $rtp) {
            $property = $rtp->property();
            $term = $property->term();
            $labelsAndComments[$term] = $rtp->labelsAndCommentsByDataType();
            $hasMultipleLabels = $hasMultipleLabels
                || count($rtp->labels()) > 1;
        }

        if (!$hasMultipleLabels) {
            return $this->prependGroupsToValues($resource, $values, $groups);
        }

        // Prepare values to display when specific labels are defined for some
        // data types for some properties.
        // So add a key with the prepared label for the data type.
        $valuesWithLabel = [];
        $dataTypesLabelsToComments = [];
        foreach ($values as $term => $propertyData) {
            /** @var \Omeka\Api\Representation\PropertyRepresentation $property */
            $property = $propertyData['property'];
            foreach ($propertyData['values'] as $value) {
                $dataType = $value->type();
                $dataTypeLabel = $labelsAndComments[$term][$dataType]['label']
                    ?? $labelsAndComments[$term]['default']['label']
                    // Manage properties appended to a resource that are not in
                    // the template for various reasons.
                    ?? $translator->translate($property->label());
                $valuesWithLabel[$term][$dataTypeLabel]['values'][] = $value;
                $dataTypesLabelsToComments[$dataTypeLabel] = $labelsAndComments[$term][$dataType]['comment']
                    ?? $labelsAndComments[$term]['default']['comment']
                    ?? $translator->translate($property->comment());
            }
        }

        foreach ($values as $term => &$propertyData) {
            $currentGroup = null;
            foreach ($groups as $groupLabel => $termLabels) {
                if (in_array($term, $termLabels)) {
                    $currentGroup = $groupLabel;
                    break;
                }
            }
            $propertyData = [
                'group' => $currentGroup,
                'term' => $term,
            ] + $propertyData;
        }
        unset($propertyData);

        // Instead of an array, use an iterator to keep the same term for
        // multiple propertyDatas.
        $newValues = new \AppendIterator();
        $hasGroups = !empty($groups);
        $currentGroup = null;
        foreach ($valuesWithLabel as $term => $propData) {
            foreach ($propData as $dataTypeLabel => $propertyData) {
                $termLabel = "$term/$dataTypeLabel";
                if ($hasGroups) {
                    $currentGroup = null;
                    foreach ($groups as $groupLabel => $termLabels) {
                        foreach ($termLabels as $termLab) {
                            $simpleTerm = strpos($termLab, '/') === false;
                            if ($termLab === ($simpleTerm ? $term : $termLabel)) {
                                $currentGroup = $groupLabel;
                                break 2;
                            }
                        }
                    }
                }
                // Unset values to keep it at the end of the array.
                unset($propertyData['values']);
                $propertyData['group'] = $currentGroup;
                $propertyData['term'] = $term;
                $propertyData['term_label'] = $termLabel;
                $propertyData['property'] = $values[$term]['property'];
                $propertyData['alternate_label'] = $dataTypeLabel;
                $propertyData['alternate_comment'] = $dataTypesLabelsToComments[$dataTypeLabel];
                $propertyData['values'] = $valuesWithLabel[$term][$dataTypeLabel]['values'];
                $newValues->append(new \ArrayIterator([$term => $propertyData]));
            }
        }

        return $newValues;
    }

    /**
     * Convert selected property values to links.
     *
     * @todo Factorize handleRepresentationValueHtml() and handleViewResourceShowValue().
     *
     * Adapted:
     * @see \AdvancedResourceTemplate\Module::handleRepresentationValueHtml()
     * @see \AdvancedSearch\View\Helper\SearchingValue::__invoke()
     */
    public function handleRepresentationValueHtml(Event $event): void
    {
        /**
         * @var \Omeka\Api\Representation\ValueRepresentation $value
         * @var \Omeka\Settings\Settings $settings
         * @var \Omeka\Settings\SiteSettings $siteSettings
         * @var \Omeka\Mvc\Status $status
         * @var \Omeka\View\Helper\Url $url
         * @var \Omeka\View\Helper\Hyperlink $hyperlink
         * @var \Laminas\View\Helper\EscapeHtml $escape
         * @var \Laminas\View\Helper\EscapeHtmlAttr $escapeAttr
         * @var \AdvancedSearch\Api\Representation\SearchConfigRepresentation $advancedSearchConfig
         */
        static $isAdmin;
        static $isSite;
        static $display;
        static $whitelist;
        static $blacklist;
        static $whitelistAll;
        static $url;
        static $hyperlink;
        static $escapeAttr;
        static $siteSlug;
        static $text;
        static $advancedSearchConfig;
        static $isInternalSearch;

        if ($display === false) {
            return;
        } elseif ($display === null) {
            $services = $this->getServiceLocator();
            $status = $services->get('Omeka\Status');
            $isSite = $status->isSiteRequest();
            $isAdmin = $status->isAdminRequest();
            $settings = $services->get('Omeka\Settings');
            // Warning: some background jobs may need to get full html.
            if (!$isSite && !$isAdmin) {
                $display = false;
                return;
            } elseif ($isSite) {
                $siteSettings = $services->get('Omeka\Settings\Site');
                $displaySite = $siteSettings->get('advancedresourcetemplate_properties_display_site');
                if ($displaySite === 'site') {
                    $sSettings = $siteSettings;
                } elseif ($displaySite === 'main') {
                    $sSettings = $settings;
                } else {
                    $display = false;
                    return;
                }
            } elseif (!$settings->get('advancedresourcetemplate_properties_display_admin')) {
                $display = false;
                return;
            } else {
                // Admin.
                $sSettings = $settings;
            }

            $allowed = [
                'prepend_icon_search',
                'prepend_icon_advanced_search',
                'prepend_icon_resource',
                'prepend_icon_uri',
                'value_search',
                'value_advanced_search',
                'value_text_resource',
                'value_text_uri',
                'append_icon_search',
                'append_icon_advanced_search',
                'append_icon_resource',
                'append_icon_uri',
            ];

            $display = (array) $sSettings->get('advancedresourcetemplate_properties_display', []);
            $display = array_values(array_intersect($allowed, $display));
            if (!$display) {
                $display = false;
                return;
            }

            $whitelist = $sSettings->get('advancedresourcetemplate_properties_as_search_whitelist', []);
            $blacklist = $sSettings->get('advancedresourcetemplate_properties_as_search_blacklist', []);
            $whitelistAll = in_array('all', $whitelist);
            if (!$whitelist) {
                $display = false;
                return;
            }

            $helpers = $services->get('ViewHelperManager');

            // Don't use plugin url because it requires a valid controller in
            // background job.
            $url = $helpers->get('url');
            $escape = $helpers->get('escapeHtml');
            $translate = $helpers->get('translate');
            $hyperlink = $helpers->get('hyperlink');
            $escapeAttr = $helpers->get('escapeHtmlAttr');
            $advancedSearchConfig = $helpers->has('getSearchConfig') ? $helpers->get('getSearchConfig') : null;
            $siteSlug = $isSite ? $status->getRouteParam('site-slug') : null;

            $display = array_replace(array_fill_keys($allowed, false), array_fill_keys($display, true));

            $display['icon_search'] = $display['prepend_icon_search'] || $display['append_icon_search'];
            $display['icon_resource'] = $display['prepend_icon_resource'] || $display['append_icon_resource'];
            $display['icon_uri'] = $display['prepend_icon_uri'] || $display['append_icon_uri'];
            $display['search'] = $display['value_search'] || $display['icon_search'];
            $display['default'] = !$display['value_search'] && !$display['value_advanced_search'];
            $display['advanced_search'] = false;

            if ($advancedSearchConfig) {
                $display['icon_advanced_search'] = $display['prepend_icon_advanced_search'] || $display['append_icon_advanced_search'];
                $display['advanced_search'] = $display['value_advanced_search'] || $display['icon_advanced_search'];
                $advancedSearchConfig = $display['advanced_search'] ? $advancedSearchConfig() : null;
                $searchEngine = $advancedSearchConfig ? $advancedSearchConfig->searchEngine() : null;
                $querier = $searchEngine ? $searchEngine->querier() : null;
                $isInternalSearch = $querier instanceof \AdvancedSearch\Querier\InternalQuerier;
                // Fallback to standard search for module Advanced search.
                if ($display['advanced_search'] && (!$querier || $querier instanceof \AdvancedSearch\Querier\NoopQuerier)) {
                    // Update derivative display keys first to get fallback.
                    $display['value_search'] = $display['value_search'] || $display['value_advanced_search'];
                    $display['prepend_icon_search'] = $display['prepend_icon_search'] || $display['prepend_icon_advanced_search'];
                    $display['append_icon_search'] = $display['append_icon_search'] || $display['append_icon_advanced_search'];
                    $display['icon_search'] = $display['prepend_icon_search'] || $display['append_icon_search'];
                    $display['search'] = $display['value_search'] || $display['icon_search'];
                    $display['default'] = !$display['value_search'];
                    // Reset derivative display keys for advanced search.
                    $display['value_advanced_search'] = false;
                    $display['prepend_icon_advanced_search'] = false;
                    $display['append_icon_advanced_search'] = false;
                    $display['icon_advanced_search'] = false;
                    $display['advanced_search'] = false;
                }
            }

            // In admin, the links for linked resource and uri are appended by
            // default, so don't append them twice.
            // Furthermore, there may be an issue with the icon used in site.
            if ($isAdmin && $display['default']) {
                if ($display['append_icon_resource']) {
                    $display['append_icon_resource'] = false;
                    $display['icon_resource'] = $display['prepend_icon_resource'];
                }
                if ($display['append_icon_uri']) {
                    $display['append_icon_uri'] = false;
                    $display['icon_uri'] = $display['prepend_icon_uri'];
                }
            }

            $text['search'] = $escape($translate('Search this value')); // @translate
            $text['item'] = $escape($translate('Show this item')); // @translate
            $text['media'] = $escape($translate('Show this media')); // @translate
            $text['item-set'] = $escape($translate('Show this item set')); // @translate
            $text['resource'] = $escape($translate('Show this resource')); // @translate
            $text['uri'] = $escape($translate('Open this external uri in a new tab')); // @translate
        }

        $value = $event->getTarget();
        $property = $value->property()->term();
        if ($whitelistAll) {
            if (in_array($property, $blacklist)) {
                return;
            }
        } elseif (!in_array($property, $whitelist)) {
            return;
        }

        // When the value is attached to a value annotation, there may be no
        // resource.
        // TODO Manage the settings for the value annotations (links, icons).
        $resource = $value->resource();
        $controllerName = $resource ? $resource->getControllerName() : null;
        if (!$controllerName) {
            $display = false;
            return;
        }

        $html = $event->getParam('html');

        $vr = $value->valueResource();
        $uri = $value->uri();
        $val = (string) $value->value();

        $result = [
            'prepend_icon_search' => '',
            'prepend_icon_advanced_search' => '',
            'prepend_icon_resource' => '',
            'prepend_icon_uri' => '',
            'value_default' => '',
            'value_search' => '',
            'value_advanced_search' => '',
            'append_icon_search' => '',
            'append_icon_advanced_search' => '',
            'append_icon_resource' => '',
            'append_icon_uri' => '',
        ];

        if ($display['default']) {
            if ($vr && $display['value_text_resource']) {
                $result['value_default'] = strip_tags($html);
            } elseif ($uri && $display['value_text_uri']) {
                $result['value_default'] = strip_tags($html);
            } else {
                $result['value_default'] = $html;
            }
        }

        if ($display['search']) {
            if ($vr) {
                $searchUrl = $url(
                    $isAdmin ? 'admin/default' : 'site/resource',
                    ['site-slug' => $siteSlug, 'controller' => $controllerName, 'action' => 'browse'],
                    ['query' => [
                        'property[0][property]' => $property,
                        'property[0][type]' => 'res',
                        'property[0][text]' => $vr->id(),
                    ]]
                );
            } else {
                $searchUrl = $url(
                    $isAdmin ? 'admin/default' : 'site/resource',
                    ['site-slug' => $siteSlug, 'controller' => $controllerName, 'action' => 'browse'],
                    ['query' => [
                        'property[0][property]' => $property,
                        'property[0][type]' => 'eq',
                        'property[0][text]' => $uri ?: $val,
                    ]]
                );
            }
            if ($display['value_search']) {
                $searchLabel = $vr ? $html : (strlen($val) ? $val : $uri);
                $result['value_search'] = $hyperlink(strip_tags($searchLabel), $searchUrl, ['class' => 'metadata-search-link']);
            }
            if ($display['icon_search']) {
                $htmlIconSearch = sprintf('<a href="%1$s" class="metadata-search-link" ><span title="%2$s" class="o-icon-search"></span></a>', $escapeAttr($searchUrl), $text['search']);
                $result['prepend_icon_search'] = $display['prepend_icon_search'] ? $htmlIconSearch : '';
                $result['append_icon_search'] = $display['append_icon_search'] ? $htmlIconSearch : '';
            }
        }

        if ($display['advanced_search']) {
            $uriOrVal = $uri ?: $val;

            // For solr, at the choice of the administrator, the index may use
            // the real title for the value resource and no id.

            // There is currently no way to convert a query to a request, so do
            // it manually, because terms are managed in all queriers anyway.
            /*
            $query = new \AdvancedSearch\Query();
            if ($vr) {
                $query->addFilterQuery($property, $vr->id(), 'res');
            } else {
                $val = (string) $value->value();
                $query->addFilter($property, $uriOrVal);
            }
            $urlQuery = $advancedSearchConfig->toRequest($query);
            */

            if ($isInternalSearch) {
                $urlQuery = [
                    'filter' => [[
                        'field' => $property,
                        'type' => $vr ? 'res' : 'eq',
                        'val' => $vr ? $vr->id() : $uriOrVal,
                    ]],
                ];
            } else {
                $prop = is_array($property) && !$advancedSearchConfig
                    ? reset($property)
                    : $property;
                // For resource, the id may or may not be indexed in Solr, so
                // use title. And the property may not be indexed too, anyway.
                if ($vr) {
                    $urlQuery = ['filter' => [
                        [
                            'field' => $prop,
                            'type' => 'res',
                            'val' => $vr->id(),
                        ],
                        [
                            'join' => 'or',
                            'field' => $prop,
                            'type' => 'eq',
                            'val' => $vr->displayTitle(),
                        ],
                    ]];
                } else {
                    $urlQuery = [
                        'filter' => [[
                            'field' => $prop,
                            'type' => 'eq',
                            'val' => $uriOrVal,
                        ]],
                    ];
                }
            }
            $searchUrl = $isAdmin
                ? $advancedSearchConfig->adminSearchUrl(false, $urlQuery)
                : $advancedSearchConfig->siteUrl($siteSlug, false, $urlQuery);
            if ($display['value_advanced_search']) {
                $searchLabel = $vr ? $html : (strlen($val) ? $val : $uri);
                $result['value_advanced_search'] = $hyperlink(strip_tags($searchLabel), $searchUrl, ['class' => 'metadata-search-link']);
            }
            if ($display['icon_advanced_search']) {
                $htmlIconSearch = sprintf('<a href="%1$s" class="metadata-search-link" ><span title="%2$s" class="o-icon-search"></span></a>', $escapeAttr($searchUrl), $text['search']);
                $result['prepend_icon_advanced_search'] = $display['prepend_icon_advanced_search'] ? $htmlIconSearch : '';
                $result['append_icon_advanced_search'] = $display['append_icon_advanced_search'] ? $htmlIconSearch : '';
            }
        }

        if ($display['icon_resource'] && $vr) {
            $vrType = $vr->getControllerName() ?? 'resource';
            $vrName = $vr->resourceName() ?? 'resources';
            $vrUrl = $isAdmin ? $vr->adminUrl() : $vr->siteUrl($siteSlug);
            $htmlIconResource = $isAdmin
                ? sprintf('<a href="%1$s" class="resource-link"><span title="%2$s" class="resource-name"></a>', $escapeAttr($vrUrl), $text[$vrType])
                : sprintf('<a href="%1$s" class="resource-link"><span title="%2$s" class="o-icon-%3$s resource-name"></span></a>', $escapeAttr($vrUrl), $text[$vrType], $vrName);
            $result['prepend_icon_resource'] = $display['prepend_icon_resource'] ? $htmlIconResource : '';
            $result['append_icon_resource'] = $display['append_icon_resource'] ? $htmlIconResource : '';
        }

        if ($display['icon_uri'] && $uri) {
            $htmlIconUri = sprintf($isAdmin
                ? '<a href="%1$s" class="uri-value-link" target="_blank" rel="noopener" title="%2$s"></a>'
                : '<a href="%1$s" class="uri-value-link" target="_blank" rel="noopener"><span title="%2$s" class="o-icon-external"></span></a>',
                $escapeAttr($uri),
                $text['uri']
            );
            $result['prepend_icon_uri'] = $display['prepend_icon_uri'] ? $htmlIconUri : '';
            $result['append_icon_uri'] = $display['append_icon_uri'] ? $htmlIconUri : '';
        }

        $event->setParam('html', implode(' ', array_filter($result, 'strlen')));
    }

    /**
     * Append icons to selected property values.
     *
     * @todo Factorize handleRepresentationValueHtml() and handleViewResourceShowValue().
     */
    public function handleViewResourceShowValue(Event $event): void
    {
        /**
         * @var \Omeka\Api\Representation\ValueRepresentation $value
         * @var \Omeka\Settings\Settings $settings
         * @var \Omeka\Settings\SiteSettings $siteSettings
         * @var \Omeka\Mvc\Status $status
         * @var \Omeka\View\Helper\Url $url
         * @var \Laminas\View\Helper\EscapeHtml $escape
         * @var \Laminas\View\Helper\EscapeHtmlAttr $escapeAttr
         * @var \AdvancedSearch\Api\Representation\SearchConfigRepresentation $advancedSearchConfig
         */
        static $isAdmin;
        static $isSite;
        static $display;
        static $whitelist;
        static $blacklist;
        static $whitelistAll;
        static $url;
        static $escapeAttr;
        static $siteSlug;
        static $text;
        static $advancedSearchConfig;
        static $isInternalSearch;

        if ($display === false) {
            return;
        } elseif ($display === null) {
            $services = $this->getServiceLocator();
            $status = $services->get('Omeka\Status');
            $isSite = $status->isSiteRequest();
            $isAdmin = $status->isAdminRequest();
            $settings = $services->get('Omeka\Settings');
            // Warning: some background jobs may need to get full html.
            if (!$isSite && !$isAdmin) {
                $display = false;
                return;
            } elseif ($isSite) {
                $siteSettings = $services->get('Omeka\Settings\Site');
                $displaySite = $siteSettings->get('advancedresourcetemplate_properties_display_site');
                if ($displaySite === 'site') {
                    $sSettings = $siteSettings;
                } elseif ($displaySite === 'main') {
                    $sSettings = $settings;
                } else {
                    $display = false;
                    return;
                }
            } elseif (!$settings->get('advancedresourcetemplate_properties_display_admin')) {
                $display = false;
                return;
            } else {
                // Admin.
                $sSettings = $settings;
            }

            $allowed = [
                'record_append_icon_search',
                'record_append_icon_advanced_search',
                'record_append_icon_resource',
                'record_append_icon_uri',
            ];

            $display = (array) $sSettings->get('advancedresourcetemplate_properties_display', []);
            $display = array_values(array_intersect($allowed, $display));
            if (!$display) {
                $display = false;
                return;
            }

            $whitelist = $sSettings->get('advancedresourcetemplate_properties_as_search_whitelist', []);
            $blacklist = $sSettings->get('advancedresourcetemplate_properties_as_search_blacklist', []);
            $whitelistAll = in_array('all', $whitelist);
            if (!$whitelist) {
                $display = false;
                return;
            }

            $helpers = $services->get('ViewHelperManager');

            // Don't use plugin url because it requires a valid controller in
            // background job.
            $url = $helpers->get('url');
            $escape = $helpers->get('escapeHtml');
            $translate = $helpers->get('translate');
            $escapeAttr = $helpers->get('escapeHtmlAttr');
            $advancedSearchConfig = $helpers->has('getSearchConfig') ? $helpers->get('getSearchConfig') : null;
            $siteSlug = $isSite ? $status->getRouteParam('site-slug') : null;

            $display = array_replace(array_fill_keys($allowed, false), array_fill_keys($display, true));

            $display['icon_search'] = $display['record_append_icon_search'];
            $display['icon_resource'] = $display['record_append_icon_resource'];
            $display['icon_uri'] = $display['record_append_icon_uri'];
            $display['search'] = $display['icon_search'];

            if ($advancedSearchConfig && !empty($display['record_append_icon_advanced_search'])) {
                $display['icon_advanced_search'] = $display['record_append_icon_advanced_search'];
                $display['advanced_search'] = $display['icon_advanced_search'];
                $advancedSearchConfig = $display['advanced_search'] ? $advancedSearchConfig() : null;
                $searchEngine = $advancedSearchConfig ? $advancedSearchConfig->searchEngine() : null;
                $querier = $searchEngine ? $searchEngine->querier() : null;
                $isInternalSearch = $querier instanceof \AdvancedSearch\Querier\InternalQuerier;
                // Fallback to standard search for module Advanced search.
                if ($display['advanced_search'] && (!$querier || $querier instanceof \AdvancedSearch\Querier\NoopQuerier)) {
                    $display['record_append_icon_search'] = true;
                    $display['record_append_icon_advanced_search'] = false;
                    $display['icon_search'] = true;
                    $display['icon_advanced_search'] = false;
                    $display['search'] = true;
                    $display['advanced_search'] = false;
                }
            } else {
                $display['advanced_search'] = false;
            }

            // In admin, the links for linked resource and uri are appended by
            // default, so don't append them twice.
            // Furthermore, there may be an issue with the icon used in site.
            if ($isAdmin) {
                if ($display['record_append_icon_resource']) {
                    $display['record_append_icon_resource'] = false;
                    $display['icon_resource'] = false;
                }
                if ($display['record_append_icon_uri']) {
                    $display['record_append_icon_uri'] = false;
                    $display['icon_uri'] = false;
                }
            }

            $text['search'] = $escape($translate('Search this value')); // @translate
            $text['item'] = $escape($translate('Show this item')); // @translate
            $text['media'] = $escape($translate('Show this media')); // @translate
            $text['item-set'] = $escape($translate('Show this item set')); // @translate
            $text['resource'] = $escape($translate('Show this resource')); // @translate
            $text['uri'] = $escape($translate('Open this external uri in a new tab')); // @translate
        }

        $value = $event->getParam('value');
        $property = $value->property()->term();
        if ($whitelistAll) {
            if (in_array($property, $blacklist)) {
                return;
            }
        } elseif (!in_array($property, $whitelist)) {
            return;
        }

        $resource = $value->resource();
        $controllerName = $resource->getControllerName();
        if (!$controllerName) {
            $display = false;
            return;
        }

        $vr = $value->valueResource();
        $uri = $value->uri();
        $val = (string) $value->value();

        $result = [
            'record_append_icon_search' => '',
            'record_append_icon_advanced_search' => '',
            'record_append_icon_resource' => '',
            'record_append_icon_uri' => '',
        ];

        if ($display['search']) {
            if ($vr) {
                $searchUrl = $url(
                    $isAdmin ? 'admin/default' : 'site/resource',
                    ['site-slug' => $siteSlug, 'controller' => $controllerName, 'action' => 'browse'],
                    ['query' => [
                        'property[0][property]' => $property,
                        'property[0][type]' => 'res',
                        'property[0][text]' => $vr->id(),
                    ]]
                );
            } else {
                $searchUrl = $url(
                    $isAdmin ? 'admin/default' : 'site/resource',
                    ['site-slug' => $siteSlug, 'controller' => $controllerName, 'action' => 'browse'],
                    ['query' => [
                        'property[0][property]' => $property,
                        'property[0][type]' => 'eq',
                        'property[0][text]' => $uri ?: $val,
                    ]]
                );
            }
            if ($display['icon_search']) {
                $htmlIconSearch = sprintf('<a href="%1$s" class="metadata-search-link" ><span title="%2$s" class="o-icon-search"></span></a>', $escapeAttr($searchUrl), $text['search']);
                $result['record_append_icon_search'] = $display['record_append_icon_search'] ? $htmlIconSearch : '';
            }
        }

        if ($display['advanced_search']) {
            $uriOrVal = $uri ?: $val;

            // For solr, at the choice of the administrator, the index may use
            // the real title for the value resource and no id.

            // There is currently no way to convert a query to a request, so do
            // it manually, because terms are managed in all queriers anyway.

            if ($isInternalSearch) {
                $urlQuery = [
                    'filter' => [[
                        'field' => $property,
                        'type' => $vr ? 'res' : 'eq',
                        'val' => $vr ? $vr->id() : $uriOrVal,
                    ]],
                ];
            } else {
                $prop = is_array($property) && !$advancedSearchConfig
                    ? reset($property)
                    : $property;

                // For resource, the id may or may not be indexed in Solr, so
                // use title. And the property may not be indexed too, anyway.
                if ($vr) {
                    $urlQuery = ['filter' => [
                        [
                            'field' => $prop,
                            'type' => 'res',
                            'val' => $vr->id(),
                        ],
                        [
                            'join' => 'or',
                            'field' => $prop,
                            'type' => 'eq',
                            'val' => $vr->displayTitle(),
                        ],
                    ]];
                } else {
                    $urlQuery = [
                        'filter' => [[
                            'field' => $prop,
                            'type' => 'eq',
                            'val' => $uriOrVal,
                        ]],
                    ];
                }
            }
            $searchUrl = $isAdmin
                ? $advancedSearchConfig->adminSearchUrl(false, $urlQuery)
                : $advancedSearchConfig->siteUrl($siteSlug, false, $urlQuery);
            if ($display['icon_advanced_search']) {
                $htmlIconSearch = sprintf('<a href="%1$s" class="metadata-search-link" ><span title="%2$s" class="o-icon-search"></span></a>', $escapeAttr($searchUrl), $text['search']);
                $result['record_append_icon_advanced_search'] = $display['record_append_icon_advanced_search'] ? $htmlIconSearch : '';
            }
        }

        if ($display['icon_resource'] && $vr) {
            $vrType = $vr->getControllerName() ?? 'resource';
            $vrName = $vr->resourceName() ?? 'resources';
            $vrUrl = $isAdmin ? $vr->adminUrl() : $vr->siteUrl($siteSlug);
            $htmlIconResource = $isAdmin
                ? sprintf('<a href="%1$s" class="resource-link"><span title="%2$s" class="resource-name"></a>', $escapeAttr($vrUrl), $text[$vrType])
                : sprintf('<a href="%1$s" class="resource-link"><span title="%2$s" class="o-icon-%3$s resource-name"></span></a>', $escapeAttr($vrUrl), $text[$vrType], $vrName);
            $result['record_append_icon_resource'] = $display['record_append_icon_resource'] ? $htmlIconResource : '';
        }

        if ($display['icon_uri'] && $uri) {
            $htmlIconUri = sprintf($isAdmin
                ? '<a href="%1$s" class="uri-value-link" target="_blank" rel="noopener" title="%2$s"></a>'
                : '<a href="%1$s" class="uri-value-link" target="_blank" rel="noopener"><span title="%2$s" class="o-icon-external"></span></a>',
                $escapeAttr($uri),
                $text['uri']
            );
            $result['record_append_icon_uri'] = $display['record_append_icon_uri'] ? $htmlIconUri : '';
        }

        echo implode(' ', array_filter($result, 'strlen'));
    }

    public function addAdminResourceHeaders(Event $event): void
    {
        /** @var \Laminas\View\Renderer\PhpRenderer $view */
        $view = $event->getTarget();

        $plugins = $view->getHelperPluginManager();
        $params = $plugins->get('params');
        $action = $params->fromRoute('action');
        if (!in_array($action, ['add', 'edit'])) {
            return;
        }

        $setting = $plugins->get('setting');
        $resourceFormElements = $setting('advancedresourcetemplate_resource_form_elements', [
            'metadata_collapse',
            'metadata_description',
            'language',
            'visibility',
            'value_annotation',
            'more_actions',
        ]) ?: [];

        $classes = [];
        $classesElements = [
            'art-no-metadata-description' => 'metadata_description',
            'art-no-language' => 'language',
            'art-no-visibility' => 'visibility',
            'art-no-value-annotation' => 'value_annotation',
            'art-no-more-actions' => 'more_actions',
        ];

        $classes = array_diff($classesElements, $resourceFormElements);

        if (isset($classes['art-no-visibility']) || isset($classes['art-no-value-annotation'])) {
            $classes['art-no-more-actions'] = true;
        } elseif (isset($classes['art-no-more-actions'])
            && !isset($classes['art-no-visibility'])
            && !isset($classes['art-no-value-annotation'])
        ) {
            $classes['art-direct-buttons'] = true;
        }
        if (!isset($classes['art-no-metadata-description']) && in_array('metadata_collapse', $resourceFormElements)) {
            $classes['art-metadata-collapse'] = true;
        }

        $isModal = $params->fromQuery('window') === 'modal';
        if ($isModal) {
            $classes['modal'] = true;
        }

        if (count($classes)) {
            $plugins->get('htmlElement')('body')->appendAttribute('class', implode(' ', array_keys($classes)));
        }

        $assetUrl = $plugins->get('assetUrl');
        $plugins->get('headLink')->appendStylesheet($assetUrl('css/advanced-resource-template-admin.css', 'AdvancedResourceTemplate'));
        $plugins->get('headScript')
            ->appendFile($assetUrl('vendor/jquery-autocomplete/jquery.autocomplete.min.js', 'AdvancedResourceTemplate'), 'text/javascript', ['defer' => 'defer'])
            ->appendFile($assetUrl('js/advanced-resource-template-admin.js', 'AdvancedResourceTemplate'), 'text/javascript', ['defer' => 'defer']);
    }

    public function handleResourceForm(Event $event): void
    {
        // TODO Remove the admin check for contribute (or copy the feature in the module).

        /** @var \Omeka\Mvc\Status $status */
        $services = $this->getServiceLocator();
        $status = $services->get('Omeka\Status');
        if (!$status->isAdminRequest()) {
            return;
        }

        $settings = $services->get('Omeka\Settings');

        /** @var \Omeka\Form\ResourceForm $form */
        $form = $event->getTarget();

        // Limit resource templates to the current resource type.
        $resourceName = $this->getRouteResourceName($status);
        if ($resourceName && $form->has('o:resource_template[o:id]')) {
            /** @var \Omeka\Form\Element\ResourceSelect $templateSelect */
            $templateSelect = $form->get('o:resource_template[o:id]');
            $templateSelectOptions = $templateSelect->getOptions();
            $templateSelectOptions['resource_value_options']['query'] ??= [];
            $templateSelectOptions['resource_value_options']['query']['resource'] = $resourceName;
            // TODO The process is not optimal in the core, since the value options are set early when options are set.
            $templateSelect->setOptions($templateSelectOptions);
        }

        $closedPropertyList = (bool) (int) $settings->get('advancedresourcetemplate_closed_property_list');
        if ($closedPropertyList) {
            /** @var \Omeka\Form\ResourceForm $form */
            $form = $event->getTarget();
            $form->setAttribute('class', trim($form->getAttribute('class') . ' closed-property-list on-load'));
        }

        // Set the resource template id from the query for a new resource.
        // Else, it will be the user setting one.
        // This feature requires to override file appliction/view/common/resource-fields.phtml.
        if ($status->getRouteParam('action') === 'add') {
            $resourceTemplateId = $services->get('ControllerPluginManager')->get('Params')->fromQuery('resource_template_id');
            if ($resourceTemplateId && $form->has('o:resource_template[o:id]')) {
                /** @var \Omeka\Form\Element\ResourceSelect $templateSelect */
                $templateSelect = $form->get('o:resource_template[o:id]');
                $templateSelect->setValue($resourceTemplateId);
            }
        }
    }

    public function handleMainSettings(Event $event): void
    {
        $this->handleAnySettings($event, 'settings');

        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');

        $autofillers = $settings->get('advancedresourcetemplate_autofillers') ?: [];
        $value = $this->autofillersToString($autofillers);

        $fieldset = version_compare(\Omeka\Module::VERSION, '4', '<')
            ? $event->getTarget()->get('advancedresourcetemplate')
            : $event->getTarget();
        $fieldset
            ->get('advancedresourcetemplate_autofillers')
            ->setValue($value);

        $this->appendCssGroupMultiCheckbox();
    }

    public function handleMainSettingsFilters(Event $event): void
    {
        $inputFilter = version_compare(\Omeka\Module::VERSION, '4', '<')
            ? $event->getParam('inputFilter')->get('advancedresourcetemplate')
            : $event->getParam('inputFilter');
        $inputFilter
            ->add([
                'name' => 'advancedresourcetemplate_autofillers',
                'required' => false,
                'filters' => [
                    [
                        'name' => \Laminas\Filter\Callback::class,
                        'options' => [
                            'callback' => [$this, 'stringToAutofillers'],
                        ],
                    ],
                ],
            ]);
    }

    public function handleSiteSettings(Event $event): void
    {
        $this->handleAnySettings($event, 'site_settings');
        $this->appendCssGroupMultiCheckbox();
    }

    protected function appendCssGroupMultiCheckbox(): void
    {
        $css = <<<'CSS'
            .group-br::before {
                display: block;
                content: "";
            }
            .group-label[data-group-label]::before {
                content: attr(data-group-label);
                font-style: italic;
            }
            CSS;

        /** @var \Laminas\View\Helper\HeadStyle headStyle */
        $headStyle = $this->getServiceLocator()->get('ViewHelperManager')->get('headStyle');
        $headStyle->appendStyle($css);
    }

    public function handleViewLayoutResourceTemplate(Event $event): void
    {
        /** @var \Laminas\View\Renderer\PhpRenderer $view */
        $view = $event->getTarget();
        $params = $view->params()->fromRoute();
        $action = $params['action'] ?? 'browse';
        if ($action !== 'browse') {
            return;
        }

        $linkTableLabels = $view->hyperlink('Compare templates', $view->url('admin/default', ['action' => 'table-templates'], true), ['class' => 'button']);

        /** @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource */
        // Normally, the current resource should be present in vars.
        $vars = $view->vars();
        $html = $vars->offsetGet('content');
        $html = preg_replace(
            '~<div id="page-actions">(.*?)</div>~s',
            '<div id="page-actions">' . $linkTableLabels . '$1</div>',
            $html,
            1
        );

        $vars->offsetSet('content', $html);
    }

    public function appendButtonActionNewResource(Event $event): void
    {
        /**
         * @var \Laminas\View\Renderer\PhpRenderer $view
         * @var \Omeka\Api\Representation\ResourceTemplateRepresentation $resourceTemplate
         * @var \Omeka\View\Helper\UserIsAllowed $userIsAllowed
         */

        $services = $this->getServiceLocator();
        // Don't add id for anonymous creation (for module Contribute).
        $user = $services->get('Omeka\AuthenticationService')->getIdentity();
        if (!$user) {
            return;
        }

        $resourceTemplate = $event->getParam('resource');
        $useForResources = $resourceTemplate->dataValue('use_for_resources');
        if (!$useForResources || in_array('items', $useForResources)) {
            $resourceLabel = 'item';
            $controllerName = 'item';
            $resourceEntity = \Omeka\Entity\Item::class;
        } elseif (in_array('item_sets', $useForResources)) {
            $resourceLabel = 'item set';
            $controllerName = 'item-set';
            $resourceEntity = \Omeka\Entity\ItemSet::class;
        } else {
            return;
        }

        $plugins = $services->get('ViewHelperManager');
        $userIsAllowed = $plugins->get('userIsAllowed');
        if (!$userIsAllowed($resourceEntity, 'create')) {
            return;
        }

        $translate = $plugins->get('translate');
        $urlHelper = $plugins->get('url');
        $hyperlink = $plugins->get('hyperlink');

        $url = $urlHelper('admin/default', ['controller' => $controllerName, 'action' => 'add'], ['query' => ['resource_template_id' => $resourceTemplate->id()]]);
        echo sprintf('<li>%s</li>', $hyperlink('', $url, ['class' => 'o-icon-add', 'title' => sprintf($translate('Add new %s'), $translate($resourceLabel))]));
    }

    public function addResourceTemplateFormElements(Event $event): void
    {
        // For an example, see module Contribute (fully standard anyway).

        /** @var \Omeka\Form\ResourceTemplateForm $form */
        $form = $event->getTarget();
        $advancedFieldset = $this->getServiceLocator()->get('FormElementManager')
            ->get(\AdvancedResourceTemplate\Form\ResourceTemplateDataFieldset::class)
            ->setName('advancedresourcetemplate');
        // To simplify saved data, the elements are added directly to fieldset.
        $fieldset = $form->get('o:data');
        foreach ($advancedFieldset->getElements() as $element) {
            $fieldset->add($element);
        }
    }

    public function addResourceTemplatePropertyFieldsetElements(Event $event): void
    {
        // For an example, see module Contribute (fully standard anyway).

        /**
         * // @var \Omeka\Form\ResourceTemplatePropertyFieldset $fieldset
         * @var \AdvancedResourceTemplate\Form\ResourceTemplatePropertyFieldset $fieldset
         * @var \AdvancedResourceTemplate\Form\ResourceTemplatePropertyDataFieldset $advancedFieldset
         */
        $fieldset = $event->getTarget();
        $advancedFieldset = $this->getServiceLocator()->get('FormElementManager')
            ->get(\AdvancedResourceTemplate\Form\ResourceTemplatePropertyDataFieldset::class)
            ->setName('advancedresourcetemplate_property');
        // The bug inside the fieldset for o:data implies to set elements at the root.
        // Anyway, it simplifies saving data.
        // $fieldset
        //     ->get('o:data')
        //     ->add($advancedFieldset);
        foreach ($advancedFieldset->getElements() as $element) {
            $fieldset->add($element);
        }
    }

    protected function getRouteResourceName(?Status $status = null): ?string
    {
        if (!$status) {
            /** @var \Omeka\Mvc\Status $status */
            $services = $this->getServiceLocator();
            $status = $services->get('Omeka\Status');
        }

        // Limit resource templates to the current resource type.
        // The resource type can be known only via the route.
        $controllerToResourceNames = [
            'Omeka\Controller\Admin\Item' => 'items',
            'Omeka\Controller\Admin\Media' => 'media',
            'Omeka\Controller\Admin\ItemSet' => 'item_sets',
            'Omeka\Controller\Site\Item' => 'items',
            'Omeka\Controller\Site\Media' => 'media',
            'Omeka\Controller\Site\ItemSet' => 'item_sets',
            'item' => 'items',
            'media' => 'media',
            'item-set' => 'item_sets',
            'items' => 'items',
            'itemset' => 'item_sets',
            'item_sets' => 'item_sets',
            // Module Annotate.
            'Annotate\Controller\Admin\Annotation' => 'annotations',
            'Annotate\Controller\Site\Annotation' => 'annotations',
            'annotation' => 'annotations',
            'annotations' => 'annotations',
        ];
        $params = $status->getRouteMatch()->getParams();
        $controller = $params['controller'] ?? $params['__CONTROLLER__'] ?? null;

        return $controllerToResourceNames[$controller] ?? null;
    }

    /**
     * Store some settings of ressource templates in settngs for easier process.
     *
     * Instead of multiplying columns in the database table resource_template_data,
     * some settings are managed differently for now.
     */
    protected function storeResourceTemplateSettings(): void
    {
        // Resource templates can be searched only by id or by label, not data,
        // but they should be searched by option "use_for_resources" in many
        // places, so it is stored in main settings too.
        // TODO To store the options for available templates by resource is possible, but probably useless.

        /**
         * @var \Doctrine\DBAL\Connection $connection
         * @var \Omeka\Settings\Settings $settings
         */
        $services = $this->getServiceLocator();
        $connection = $services->get('Omeka\Connection');
        $settings = $services->get('Omeka\Settings');

        // The connection is required because the module entities are not
        // available during upgrade.

        // Since data are json, it's hard to extract them with mysql < 8, so
        // process here.
        $qb = $connection->createQueryBuilder();
        $qb
            ->select(
                'resource_template.id',
                'resource_template_data.data',
            )
            ->from('resource_template')
            ->leftJoin('resource_template', 'resource_template_data', 'resource_template_data', 'resource_template_data.resource_template_id = resource_template.id')
        ;
        $templatesData = $connection->executeQuery($qb)->fetchAllKeyValue();
        $templatesByResourceNames = [
            'items' => [],
            'media' => [],
            'item_sets' => [],
            'value_annotations' => [],
            // Module Annotate.
            'annotations' => [],
        ];
        foreach ($templatesData as $templateId => $templateData) {
            $templateId = (int) $templateId;
            $templateData = $templateData ? json_decode($templateData, true) : null;
            if ($templateData === null
                // When null or empty array, the template is not used.
                || !array_key_exists('use_for_resources', $templateData)
            ) {
                $templatesByResourceNames['items'][] = $templateId;
                $templatesByResourceNames['media'][] = $templateId;
                $templatesByResourceNames['item_sets'][] = $templateId;
                $templatesByResourceNames['value_annotations'][] = $templateId;
                $templatesByResourceNames['annotations'][] = $templateId;
            } elseif (is_array($templateData['use_for_resources'])) {
                foreach ($templateData['use_for_resources'] as $resourceName) {
                    $templatesByResourceNames[$resourceName][] = $templateId;
                }
            }
        }
        $settings->set('advancedresourcetemplate_templates_by_resource', $templatesByResourceNames);
    }

    protected function autofillersToString($autofillers)
    {
        if (is_string($autofillers)) {
            return $autofillers;
        }

        $result = '';
        foreach ($autofillers as $key => $autofiller) {
            $label = empty($autofiller['label']) ? '' : $autofiller['label'];
            $result .= $label ? "[$key] = $label\n" : "[$key]\n";
            if (!empty($autofiller['url'])) {
                $result .= $autofiller['url'] . "\n";
            }
            if (!empty($autofiller['query'])) {
                $result .= '?' . $autofiller['query'] . "\n";
            }
            if (!empty($autofiller['mapping'])) {
                // For generic resource, display the label and the list first.
                $mapping = $autofiller['mapping'];
                foreach ($autofiller['mapping'] as $key => $map) {
                    if (isset($map['to']['pattern'])
                        && in_array($map['to']['pattern'], ['{__label__}', '{list}'])
                    ) {
                        unset($mapping[$key]);
                        unset($map['to']['pattern']);
                        $mapping = [$key => $map] + $mapping;
                    }
                }
                $autofiller['mapping'] = $mapping;
                foreach ($autofiller['mapping'] as $map) {
                    $to = &$map['to'];
                    if (!empty($map['from'])) {
                        $result .= $map['from'];
                    }
                    $result .= ' = ';
                    if (!empty($to['field'])) {
                        $result .= $to['field'];
                    }
                    if (!empty($to['type'])) {
                        $result .= ' ^^' . $to['type'];
                    }
                    if (!empty($to['@language'])) {
                        $result .= ' @' . $to['@language'];
                    }
                    if (!empty($to['is_public'])) {
                        $result .= ' §' . ($to['is_public'] === 'private' ? 'private' : 'public');
                    }
                    if (!empty($to['pattern'])) {
                        $result .= ' ~ ' . $to['pattern'];
                    }
                    $result .= "\n";
                }
            }
            $result .= "\n";
        }

        return mb_substr($result, 0, -1);
    }

    public function stringToAutofillers($string)
    {
        if (is_array($string)) {
            return $string;
        }

        /** @var \AdvancedResourceTemplate\Mvc\Controller\Plugin\FieldNameToProperty $fieldNameToProperty */
        $fieldNameToProperty = $this->getServiceLocator()->get('ControllerPluginManager')->get('fieldNameToProperty');

        $result = [];
        $lines = $this->stringToList($string);
        $matches = [];
        $autofillerKey = null;
        foreach ($lines as $line) {
            // Start a new autofiller.
            $first = mb_substr($line, 0, 1);
            if ($first === '[') {
                preg_match('~^\[\s*(?<service>[a-zA-Z][\w-]*)\s*(?:\:\s*(?<sub>[a-zA-Z][a-zA-Z0-9:]*))?\s*(?:#\s*(?<variant>[^\]]+))?\s*\]\s*(?:=?\s*(?<label>.*))$~', $line, $matches);
                if (empty($matches['service'])) {
                    continue;
                }
                $autofillerKey = $matches['service']
                    . (empty($matches['sub']) ? '' : ':' . $matches['sub'])
                    . (empty($matches['variant']) ? '' : ' #' . $matches['variant']);
                $result[$autofillerKey] = [
                    'service' => $matches['service'],
                    'sub' => $matches['sub'],
                    'label' => empty($matches['label']) ? null : $matches['label'],
                    'mapping' => [],
                ];
            } elseif (!$autofillerKey) {
                // Nothing.
            } elseif ($first === '?') {
                $result[$autofillerKey]['query'] = mb_substr($line, 1);
            } elseif (mb_strpos($line, 'https://') === 0 || mb_strpos($line, 'http://') === 0) {
                $result[$autofillerKey]['url'] = $line;
            } else {
                // Fill a map of an autofiller.
                $pos = $first === '~'
                    ? mb_strpos($line, '=')
                    : mb_strrpos(strtok($line, '~'), '=');
                $from = $pos === false ? '' : trim(mb_substr($line, 0, $pos));
                $to = $pos === false ? trim($line) : trim(mb_substr($line, $pos + 1));
                if (!$from || !$to) {
                    continue;
                }
                $ton = $fieldNameToProperty($to);
                if (!$ton) {
                    continue;
                }
                $result[$autofillerKey]['mapping'][] = [
                    'from' => $from,
                    'to' => array_filter($ton, fn ($v) => !is_null($v)),
                ];
            }
        }
        return $result;
    }

    /**
     * Get each line of a string separately.
     */
    protected function stringToList($string): array
    {
        return array_filter(array_map('trim', explode("\n", $this->fixEndOfLine($string))), 'strlen');
    }

    /**
     * Clean the text area from end of lines.
     *
     * This method fixes Windows and Apple copy/paste from a textarea input.
     */
    protected function fixEndOfLine($string): string
    {
        return str_replace(["\r\n", "\n\r", "\r"], ["\n", "\n", "\n"], (string) $string);
    }
}

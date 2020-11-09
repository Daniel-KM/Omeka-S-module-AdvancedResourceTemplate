<?php declare(strict_types=1);

namespace AdvancedResourceTemplate\Controller\Admin;

use AdvancedResourceTemplate\Form\ResourceTemplatePropertyFieldset;
use Omeka\DataType\Manager as DataTypeManager;
use Omeka\Form\ConfirmForm;
use Omeka\Form\ResourceTemplateForm;
use Omeka\Form\ResourceTemplateImportForm;
// use Omeka\Form\ResourceTemplatePropertyFieldset;
use Omeka\Form\ResourceTemplateReviewImportForm;
use Omeka\Mvc\Exception\NotFoundException;
use Omeka\Stdlib\Message;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

class ResourceTemplateController extends AbstractActionController
{
    protected $dataTypeManager;

    public function __construct(DataTypeManager $dataTypeManager)
    {
        $this->dataTypeManager = $dataTypeManager;
    }

    public function browseAction()
    {
        $this->setBrowseDefaults('label', 'asc');
        $response = $this->api()->search('resource_templates', $this->params()->fromQuery());
        $this->paginator($response->getTotalResults());

        $view = new ViewModel;
        $view->setVariable('resourceTemplates', $response->getContent());
        return $view;
    }

    public function showAction()
    {
        $response = $this->api()->read('resource_templates', $this->params('id'));

        $view = new ViewModel;
        $view->setVariable('resourceTemplate', $response->getContent());
        return $view;
    }

    public function showDetailsAction()
    {
        $response = $this->api()->read('resource_templates', $this->params('id'));

        $view = new ViewModel;
        $view->setTerminal(true);
        $view->setVariable('resource', $response->getContent());
        return $view;
    }

    public function importAction()
    {
        $form = $this->params()->fromPost('import')
            ? $this->getForm(ResourceTemplateReviewImportForm::class)
            : $this->getForm(ResourceTemplateImportForm::class);
        $view = new ViewModel;

        if ($this->getRequest()->isPost()) {
            $data = $this->params()->fromPost();
            $form->setData($data);
            if ($form->isValid()) {
                $file = $this->params()->fromFiles('file');
                if ($file) {
                    // Process import form.
                    $import = json_decode(file_get_contents($file['tmp_name']), true);
                    if ($this->importIsValid($import)) {
                        $import = $this->flagValid($import);

                        $form = $this->getForm(ResourceTemplateReviewImportForm::class);
                        $form->get('import')->setValue(json_encode($import));
                        $form->get('label')->setValue($import['o:label']);

                        $view->setVariable('import', $import);
                        $view->setTemplate('omeka/admin/resource-template/review-import');
                    } else {
                        $this->messenger()->addError('Invalid import file format');
                    }
                } else {
                    // Process review import form.
                    $import = json_decode($form->getData()['import'], true);
                    $import['o:label'] = $this->params()->fromPost('label');

                    $dataTypes = $this->params()->fromPost('data_types');
                    if ($dataTypes) {
                        foreach ($dataTypes as $key => $dataTypeList) {
                            $import['o:resource_template_property'][$key]['o:data_type'] = $dataTypeList;
                        }
                    }

                    $response = $this->api($form)->create('resource_templates', $import);
                    if ($response) {
                        return $this->redirect()->toUrl($response->getContent()->url());
                    } else {
                        $form = $this->getForm(ResourceTemplateReviewImportForm::class);
                        $form->get('import')->setValue(json_encode($import));
                        $form->get('label')->setValue($import['o:label']);
                        $view->setVariable('import', $import);
                        $view->setTemplate('omeka/admin/resource-template/review-import');
                    }
                }
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }

        $view->setVariable('form', $form);
        return $view;
    }

    /**
     * Flag members and data types as valid.
     *
     * All members start as invalid until we determine whether the corresponding
     * vocabulary and member exists in this installation. All data types start
     * as "Default" (i.e. none declared) until we determine whether they match
     * the native types (literal, uri, resource).
     *
     * We flag a valid vocabulary by adding [vocabulary_prefix] to the member; a
     * valid class by adding [o:id]; and a valid property by adding
     * [o:property][o:id]. We flag a valid data type by adding [o:data_type] to
     * the property. By design, the API will only hydrate members and data types
     * that are flagged as valid.
     *
     * @todo Manage direct import of data types from Value Suggest and other modules.
     *
     * @param array $import
     * @return array
     */
    protected function flagValid(array $import)
    {
        $vocabs = [];
        $dataTypes = [
            'literal',
            'uri',
            'resource',
            'resource:item',
            'resource:itemset',
            'resource:media',
        ];

        $getVocab = function ($namespaceUri) use (&$vocabs) {
            if (isset($vocabs[$namespaceUri])) {
                return $vocabs[$namespaceUri];
            }
            $vocab = $this->api()->searchOne('vocabularies', [
                'namespace_uri' => $namespaceUri,
            ])->getContent();
            if ($vocab) {
                $vocabs[$namespaceUri] = $vocab;
                return $vocab;
            }
            return false;
        };

        if (isset($import['o:resource_class'])) {
            if ($vocab = $getVocab($import['o:resource_class']['vocabulary_namespace_uri'])) {
                $import['o:resource_class']['vocabulary_prefix'] = $vocab->prefix();
                $class = $this->api()->searchOne('resource_classes', [
                    'vocabulary_namespace_uri' => $import['o:resource_class']['vocabulary_namespace_uri'],
                    'local_name' => $import['o:resource_class']['local_name'],
                ])->getContent();
                if ($class) {
                    $import['o:resource_class']['o:id'] = $class->id();
                }
            }
        }

        foreach (['o:title_property', 'o:description_property'] as $property) {
            if (isset($import[$property])) {
                if ($vocab = $getVocab($import[$property]['vocabulary_namespace_uri'])) {
                    $import[$property]['vocabulary_prefix'] = $vocab->prefix();
                    $prop = $this->api()->searchOne('properties', [
                        'vocabulary_namespace_uri' => $import[$property]['vocabulary_namespace_uri'],
                        'local_name' => $import[$property]['local_name'],
                    ])->getContent();
                    if ($prop) {
                        $import[$property]['o:id'] = $prop->id();
                    }
                }
            }
        }

        foreach ($import['o:resource_template_property'] as $key => $property) {
            if ($vocab = $getVocab($property['vocabulary_namespace_uri'])) {
                $import['o:resource_template_property'][$key]['vocabulary_prefix'] = $vocab->prefix();
                $prop = $this->api()->searchOne('properties', [
                    'vocabulary_namespace_uri' => $property['vocabulary_namespace_uri'],
                    'local_name' => $property['local_name'],
                ])->getContent();
                if ($prop) {
                    $import['o:resource_template_property'][$key]['o:property'] = ['o:id' => $prop->id()];
                    // Check the deprecated "data_type_name" if needed and
                    // normalize it.
                    if (!array_key_exists('data_types', $import['o:resource_template_property'][$key])) {
                        if (!empty($import['o:resource_template_property'][$key]['data_type_name'])
                            && !empty($import['o:resource_template_property'][$key]['data_type_label'])
                        ) {
                            $import['o:resource_template_property'][$key]['data_types'] = [[
                                'name' => $import['o:resource_template_property'][$key]['data_type_name'],
                                'label' => $import['o:resource_template_property'][$key]['data_type_label'],
                            ]];
                        } else {
                            $import['o:resource_template_property'][$key]['data_types'] = [];
                        }
                    }
                    $importDataTypes = [];
                    foreach ($import['o:resource_template_property'][$key]['data_types'] as $dataType) {
                        $importDataTypes[$dataType['name']] = $dataType;
                    }
                    $import['o:resource_template_property'][$key]['data_types'] = $importDataTypes;
                    // Prepare the list of standard data types.
                    $import['o:resource_template_property'][$key]['o:data_type'] = [];
                    foreach ($importDataTypes as $name => $importDataType) {
                        if (in_array($name, $dataTypes)) {
                            $import['o:resource_template_property'][$key]['o:data_type'][] = $importDataType['name'];
                        }
                    }
                }
            }
        }

        return $import;
    }

    /**
     * Verify that the import format is valid.
     *
     * @param array $import
     * @return bool
     */
    protected function importIsValid($import)
    {
        if (!is_array($import)) {
            // invalid format
            return false;
        }

        if (!isset($import['o:label']) || !is_string($import['o:label'])) {
            // missing or invalid label
            return false;
        }

        // Validate class.
        if (isset($import['o:resource_class'])) {
            if (!is_array($import['o:resource_class'])) {
                // invalid o:resource_class
                return false;
            }
            if (!array_key_exists('vocabulary_namespace_uri', $import['o:resource_class'])
                || !array_key_exists('vocabulary_label', $import['o:resource_class'])
                || !array_key_exists('local_name', $import['o:resource_class'])
                || !array_key_exists('label', $import['o:resource_class'])
            ) {
                // missing o:resource_class info
                return false;
            }
            if (!is_string($import['o:resource_class']['vocabulary_namespace_uri'])
                || !is_string($import['o:resource_class']['vocabulary_label'])
                || !is_string($import['o:resource_class']['local_name'])
                || !is_string($import['o:resource_class']['label'])
            ) {
                // invalid o:resource_class info
                return false;
            }
        }

        // Validate title and description.
        foreach (['o:title_property', 'o:description_property'] as $property) {
            if (isset($import[$property])) {
                if (!is_array($import[$property])) {
                    // Invalid property.
                    return false;
                }
                if (!array_key_exists('vocabulary_namespace_uri', $import[$property])
                    || !array_key_exists('vocabulary_label', $import[$property])
                    || !array_key_exists('local_name', $import[$property])
                    || !array_key_exists('label', $import[$property])
                ) {
                    // Missing a property info.
                    return false;
                }
                if (!is_string($import[$property]['vocabulary_namespace_uri'])
                    || !is_string($import[$property]['vocabulary_label'])
                    || !is_string($import[$property]['local_name'])
                    || !is_string($import[$property]['label'])
                ) {
                    // Invalid property info.
                    return false;
                }
            }
        }

        // Validate data.
        if (array_key_exists('o:data', $import) && !is_array($import['o:data'])) {
            return false;
        }

        // Validate properties.
        if (!isset($import['o:resource_template_property']) || !is_array($import['o:resource_template_property'])) {
            // missing or invalid o:resource_template_property
            return false;
        }

        foreach ($import['o:resource_template_property'] as $property) {
            if (!is_array($property)) {
                // invalid o:resource_template_property format
                return false;
            }

            // Manage import from an export of Omeka < 3.0.
            $oldExport = !array_key_exists('data_types', $property);

            // Check missing o:resource_template_property info.
            if (!array_key_exists('vocabulary_namespace_uri', $property)
                || !array_key_exists('vocabulary_label', $property)
                || !array_key_exists('local_name', $property)
                || !array_key_exists('label', $property)
                || !array_key_exists('o:alternate_label', $property)
                || !array_key_exists('o:alternate_comment', $property)
                || !array_key_exists('o:is_required', $property)
                || !array_key_exists('o:is_private', $property)
            ) {
                return false;
            }
            if ($oldExport
                 && (!array_key_exists('data_type_name', $property)
                    || !array_key_exists('data_type_label', $property)
            )) {
                return false;
            }

            // Check invalid o:resource_template_property info.
            if (!is_string($property['vocabulary_namespace_uri'])
                || !is_string($property['vocabulary_label'])
                || !is_string($property['local_name'])
                || !is_string($property['label'])
                || (!is_string($property['o:alternate_label']) && !is_null($property['o:alternate_label']))
                || (!is_string($property['o:alternate_comment']) && !is_null($property['o:alternate_comment']))
                || !is_bool($property['o:is_required'])
                || !is_bool($property['o:is_private'])
            ) {
                return false;
            }
            if ($oldExport) {
                if ((!is_string($property['data_type_name']) && !is_null($property['data_type_name']))
                    || (!is_string($property['data_type_label']) && !is_null($property['data_type_label']))
                ) {
                    return false;
                }
            } elseif (!is_array($property['data_types']) && !is_null($property['data_types'])) {
                return false;
            }

            // Validate data.
            if (array_key_exists('o:data', $property) && !is_array($property['o:data'])) {
                return false;
            }
        }
        return true;
    }

    public function exportAction()
    {
        /** @var \Omeka\Api\Representation\ResourceTemplateRepresentation $template */
        $template = $this->api()->read('resource_templates', $this->params('id'))->getContent();
        $templateClass = $template->resourceClass();
        $templateTitle = $template->titleProperty();
        $templateDescription = $template->descriptionProperty();
        $templateData = $template->data();
        $templateProperties = $template->resourceTemplateProperties();

        $export = [
            'o:label' => $template->label(),
            'o:resource_template_property' => [],
        ];

        if ($templateClass) {
            $vocab = $templateClass->vocabulary();
            $export['o:resource_class'] = [
                'vocabulary_namespace_uri' => $vocab->namespaceUri(),
                'vocabulary_label' => $vocab->label(),
                'local_name' => $templateClass->localName(),
                'label' => $templateClass->label(),
            ];
        }

        if ($templateTitle) {
            $vocab = $templateTitle->vocabulary();
            $export['o:title_property'] = [
                'vocabulary_namespace_uri' => $vocab->namespaceUri(),
                'vocabulary_label' => $vocab->label(),
                'local_name' => $templateTitle->localName(),
                'label' => $templateTitle->label(),
            ];
        }

        if ($templateDescription) {
            $vocab = $templateDescription->vocabulary();
            $export['o:description_property'] = [
                'vocabulary_namespace_uri' => $vocab->namespaceUri(),
                'vocabulary_label' => $vocab->label(),
                'local_name' => $templateDescription->localName(),
                'label' => $templateDescription->label(),
            ];
        }

        if ($templateData) {
            $export['o:data'] = $templateData;
        }

        foreach ($templateProperties as $templateProperty) {
            $property = $templateProperty->property();
            $vocab = $property->vocabulary();

            // Note that "position" is implied by array order.
            $export['o:resource_template_property'][] = [
                'o:alternate_label' => $templateProperty->alternateLabel(),
                'o:alternate_comment' => $templateProperty->alternateComment(),
                'o:is_required' => $templateProperty->isRequired(),
                'o:is_private' => $templateProperty->isPrivate(),
                'o:data' => $templateProperty->data(),
                'data_types' => $templateProperty->dataTypeLabels(),
                'vocabulary_namespace_uri' => $vocab->namespaceUri(),
                'vocabulary_label' => $vocab->label(),
                'local_name' => $property->localName(),
                'label' => $property->label(),
            ];
        }

        $filename = preg_replace('/[^a-zA-Z0-9]+/', '_', $template->label());
        $export = json_encode($export, JSON_PRETTY_PRINT);

        $response = $this->getResponse();
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Content-Type', 'application/json')
                ->addHeaderLine('Content-Disposition', sprintf('attachment; filename="%s.json"', $filename))
                ->addHeaderLine('Content-Length', strlen($export));
        $response->setHeaders($headers);
        $response->setContent($export);
        return $response;
    }

    public function deleteConfirmAction()
    {
        $resource = $this->api()->read('resource_templates', $this->params('id'))->getContent();

        $view = new ViewModel;
        $view->setTerminal(true);
        $view->setTemplate('common/delete-confirm-details');
        $view->setVariable('resource', $resource);
        $view->setVariable('resourceLabel', 'resource template'); // @translate
        $view->setVariable('partialPath', 'omeka/admin/resource-template/show-details');
        return $view;
    }

    public function deleteAction()
    {
        if ($this->getRequest()->isPost()) {
            $form = $this->getForm(ConfirmForm::class);
            $form->setData($this->getRequest()->getPost());
            if ($form->isValid()) {
                $response = $this->api()->delete('resource_templates', $this->params('id'));
                if ($response) {
                    $this->messenger()->addSuccess('Resource template successfully deleted'); // @translate
                } else {
                    $this->messenger()->addError('Resource template could not be deleted'); // @translate
                }
            } else {
                $this->messenger()->addError('Resource template could not be deleted'); // @translate
            }
        }
        return $this->redirect()->toRoute(null, ['action' => 'browse'], true);
    }

    public function addAction()
    {
        return $this->getAddEditView(false);
    }

    public function editAction()
    {
        return $this->getAddEditView(true);
    }

    /**
     * Get the add/edit view.
     *
     * @var bool $isUpdate
     * @return ViewModel
     */
    protected function getAddEditView($isUpdate = false)
    {
        /**
         * @var \Omeka\Form\ResourceTemplateForm$form
         * // @var \Omeka\Form\ResourceTemplatePropertyFieldset $propertyFieldset
         * @var \AdvancedResourceTemplate\Form\ResourceTemplatePropertyFieldset $propertyFieldset
         */
        $form = $this->getForm(ResourceTemplateForm::class);
        $propertyFieldset = $this->getForm(ResourceTemplatePropertyFieldset::class);

        $isPost = $this->getRequest()->isPost();
        if ($isUpdate) {
            $resourceTemplate = $this->api()
                ->read('resource_templates', $this->params('id'))
                ->getContent();
            if (!$isPost) {
                // Recursive conversion into a json array.
                $data = json_decode(json_encode($resourceTemplate), true);
                $data = $this->fixDataArray($data);
                $form->setData($data);
            }
        } elseif (!$isPost) {
            $data = $this->getDefaultResourceTemplate();
            $data = $this->fixDataArray($data);
            $form->setData($data);
        }

        if ($isPost) {
            $post = $this->params()->fromPost();
            // For an undetermined reason, the fieldset "o:data" inside the
            // collection is not validated. So elements should be attached to
            // the property fieldset with attribute "data-setting-key", so then
            // can be moved in "o:data" after automatic filter and validation.
            // Anyway, the values with a nested key like o:property[o:id] should
            // be cleaned.
            $postData = $this->fixPostArray($post);
            $postData = $this->fixDataArray($postData);
            if (!empty($postData['_has_empty'])) {
                $this->messenger()->addError('When multiple fields use the same property, only one field can be without data type.'); // @translate
            }
            if (!empty($postData['_has_removed'])) {
                $this->messenger()->addError('When multiple fields use the same property, the data types must be unique among them.'); // @translate
            }

            $form->setData($postData);
            if ($form->isValid()) {
                $data = $form->getData();
                $data = $this->fixPostArray($data);
                $data = $this->fixDataPostArray($data);
                if (empty($data['_has_empty']) && empty($data['_has_removed'])) {
                    $response = $isUpdate
                        ? $this->api($form)->update('resource_templates', $resourceTemplate->id(), $data)
                        : $this->api($form)->create('resource_templates', $data);
                    if ($response) {
                        if ($isUpdate) {
                            $successMessage = 'Resource template successfully updated'; // @translate
                        } else {
                            $successMessage = new Message(
                                'Resource template successfully created. %s', // @translate
                                sprintf(
                                    '<a href="%s">%s</a>',
                                    htmlspecialchars($this->url()->fromRoute(null, [], true)),
                                    $this->translate('Add another resource template?')
                                    )
                                );
                            $successMessage->setEscapeHtml(false);
                        }
                        $this->messenger()->addSuccess($successMessage);
                        return $this->redirect()->toUrl($response->getContent()->url());
                    }
                    $this->messenger()->addFormErrors($form);
                }
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }

        return new ViewModel([
            'resourceTemplate' => $isUpdate ? $resourceTemplate : null,
            'form' => $form,
            'propertyFieldset' => $propertyFieldset,
        ]);
    }

    /**
     * Adapt the resource json to the form (avoid nesting).
     *
     * @param array $data
     * @return array
     */
    protected function fixDataArray(array $data): array
    {
        $data['o:resource_class'] = empty($data['o:resource_class']) ? null : $data['o:resource_class']['o:id'];
        $data['o:title_property'] = empty($data['o:title_property']) ? null : $data['o:title_property']['o:id'];
        $data['o:description_property'] = empty($data['o:description_property']) ? null : $data['o:description_property']['o:id'];
        if (empty($data['o:resource_template_property'])) {
            $data['o:resource_template_property'] = [];
        }
        foreach ($data['o:resource_template_property'] as $key => $value) {
            $data['o:resource_template_property'][$key]['o:property'] = $value['o:property']['o:id'];
            $data['o:resource_template_property'][$key]['o:data_type'] = empty($value['o:data_type']) ? [] : array_filter($value['o:data_type']);
            if (empty($value['o:data'])) {
                $data['o:resource_template_property'][$key]['o:data'] = [];
            }
        }
        return $this->explodePropertyTemplateData($data);
    }

    /**
     * Adapt the post to resource json.
     *
     * @param array $post
     * @return array
     */
    protected function fixPostArray(array $post): array
    {
        $post['o:resource_class'] = empty($post['o:resource_class']) ? null : ['o:id' => (int) $post['o:resource_class']];
        $post['o:title_property'] = empty($post['o:title_property']) ? null : ['o:id' => (int) $post['o:title_property']];
        $post['o:description_property'] = empty($post['o:description_property']) ? null : ['o:id' => (int) $post['o:description_property']];
        $post['o:resource_template_property'] = empty($post['o:resource_template_property']) ? [] : array_values($post['o:resource_template_property']);
        foreach ($post['o:resource_template_property'] as $key => $value) {
            if (empty($value['o:property'])) {
                unset($post['o:resource_template_property'][$key]);
                continue;
            }
            $post['o:resource_template_property'][$key]['o:property'] = ['o:id' => $value['o:property']];
            if (empty($post['o:resource_template_property'][$key]['o:data_type'])) {
                $post['o:resource_template_property'][$key]['o:data_type'] = [];
            }
            if (empty($value['o:data'])) {
                $post['o:resource_template_property'][$key]['o:data'] = [];
            }
        }
        return $this->mergePropertyTemplateData($post);
    }

    protected function fixDataPostArray(array $post): array
    {
        // Clean useless keys (anyway skipped in adapter).
        foreach ($post['o:resource_template_property'] as &$rtp) {
            foreach (array_keys($rtp) as $key) {
                if (mb_substr($key, 0, 2) !== 'o:' && !in_array($key, ['is-title-property', 'is-description-property'])) {
                    unset($rtp[$key]);
                }
            }
        }
        return $post;
    }

    /**
     * Convert template property data array from the form into full template
     * property data content like the resource template json.
     *
     * In order to support multiple template properties with the same property
     * with a simple form similar to the core one, the template properties from
     * the form are attached to a single template property according to the data
     * type, like in the model.
     *
     * The template properties order is kept, but they are gathered by property.
     *
     * @param array $data
     * @return array
     */
    protected function mergePropertyTemplateData(array $post): array
    {
        $rtps = [];
        foreach ($post['o:resource_template_property'] as $rtp) {
            $propertyId = $rtp['o:property']['o:id'];
            $rtpd = $rtp;
            unset($rtpd['o:property'], $rtpd['o:data']);
            if (empty($rtps[$propertyId])) {
                $rtp['o:data'] = [$rtpd];
                $rtps[$propertyId] = $rtp;
            } else {
                $rtps[$propertyId]['o:data_type'] = array_filter(array_unique(array_merge(
                    $rtps[$propertyId]['o:data_type'],
                    $rtpd['o:data_type']
                )));
                $rtps[$propertyId]['o:data'][] = $rtpd;
            }
        }

        // TODO Move this check somewhere in the form and in the adapter.
        foreach ($rtps as &$rtp) {
            // The data types must be unique for each property.
            if (count($rtp['o:data']) <= 1) {
                continue;
            }
            $usedDatatypes = [];
            $hasEmpty = false;
            foreach ($rtp['o:data'] as $k => &$rtpData) {
                if (empty($rtpData['o:data_type'])) {
                    if ($hasEmpty) {
                        $post['_has_empty'] = true;
                        unset($rtp['o:data'][$k]);
                    } else {
                        $hasEmpty = true;
                    }
                    continue;
                }
                $before = count($rtpData['o:data_type']);
                $rtpData['o:data_type'] = array_diff($rtpData['o:data_type'], $usedDatatypes);
                if (count($rtpData['o:data_type']) !== $before) {
                    $post['_has_removed'] = true;
                }
                if (empty($rtpData['o:data_type'])) {
                    unset($rtp['o:data'][$k]);
                    continue;
                }
                $usedDatatypes = array_merge($rtpData['o:data_type'], $usedDatatypes);
            }
        }
        $post['o:resource_template_property'] = $rtps;
        return $post;
    }

    /**
     * Convert template property data content into template property data array.

     * In order to support multiple template properties with the same property
     * with a simple form similar to the core one, the template properties are
     * duplicated for each data for the form.
     *
     * @param array $data
     * @return array
     */
    protected function explodePropertyTemplateData(array $data): array
    {
        $rtps = [];
        foreach ($data['o:resource_template_property'] as $rtp) {
            if (empty($rtp['o:data'])) {
                $rtp['o:data'] = [];
                $rtps[] = $rtp;
                continue;
            }
            foreach ($rtp['o:data'] as $rtpData) {
                $rtpd = $rtpData + $rtp;
                unset($rtpd['o:data']);
                $rtps[] = $rtpd;
            }
        }
        $data['o:resource_template_property'] = $rtps;
        return $data;
    }

    /**
     * Get the default resource template.
     *
     * @return array
     */
    protected function getDefaultResourceTemplate()
    {
        $resourceTemplate = [
            'o:label' => '',
            'o:owner' => ['o:id' => $this->identity()->getId()],
            'o:resource_class' => null,
            'o:title_property' => null,
            'o:description_property' => null,
            'o:data' => [],
            'o:resource_template_property' => [],
        ];

        $defaultProperties = ['dcterms:title', 'dcterms:description'];
        foreach ($defaultProperties as $property) {
            $property = $this->api()->searchOne(
                'properties', ['term' => $property]
            )->getContent();
            // In a Collection, "false" is not allowed for a checkbox, etc, except with input filter.
            $resourceTemplate['o:resource_template_property'][] = [
                'o:property' => ['o:id' => $property->id()],
                'o:alternate_label' => '',
                'o:alternate_comment' => '',
                'o:data_type' => [],
                'o:is_required' => 0,
                'o:is_private' => 0,
                'o:data' => [],
            ];
        }

        return $resourceTemplate;
    }

    /**
     * Return a new property row for the add-edit page.
     */
    public function addNewPropertyRowAction()
    {
        if (!$this->getRequest()->isXmlHttpRequest()) {
            throw new NotFoundException;
        }

        $property = $this->api()
            ->read('properties', $this->params()->fromQuery('property_id'))
            ->getContent();

        $propertyFieldset = $this->getForm(ResourceTemplatePropertyFieldset::class);
        $propertyFieldset->get('o:property')->setValue($property->id());

        $namePrefix = 'o:resource_template_property[' . rand((int) (PHP_INT_MAX / 1000000), PHP_INT_MAX) . ']';
        $propertyFieldset->setName($namePrefix);
        foreach ($propertyFieldset->getElements()  as $element) {
            $element->setName($namePrefix . '[' . $element->getName() . ']');
        }
        foreach ($propertyFieldset->getFieldsets()  as $fieldset) {
            $fieldset->setName($namePrefix . '[' . $fieldset->getName() . ']');
        }

        $view = new ViewModel([
            'property' => $property,
            'resourceTemplate' => null,
            'propertyFieldset' => $propertyFieldset,
        ]);
        return $view
            ->setTerminal(true)
            ->setTemplate('omeka/admin/resource-template/show-property-row');
    }
}

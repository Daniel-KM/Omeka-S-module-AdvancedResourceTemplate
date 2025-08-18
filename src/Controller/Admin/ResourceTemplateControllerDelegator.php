<?php declare(strict_types=1);

namespace AdvancedResourceTemplate\Controller\Admin;

use AdvancedResourceTemplate\Form\ResourceTemplatePropertyFieldset;
use Common\Stdlib\PsrMessage;
use Laminas\View\Model\ViewModel;
use Omeka\Api\Representation\VocabularyRepresentation;
use Omeka\Form\ResourceTemplateForm;
use Omeka\Form\ResourceTemplateImportForm;
use Omeka\Form\ResourceTemplateReviewImportForm;
use Omeka\Mvc\Exception\NotFoundException;
use Omeka\Stdlib\Message;

class ResourceTemplateControllerDelegator extends \Omeka\Controller\Admin\ResourceTemplateController
{
    public function tableTemplatesAction()
    {
        $this->browse()->setDefaults('resource_templates');
        $query = $this->params()->fromQuery();

        // Check if the query is empty to avoid to return all templates by default.
        $qQuery = $query;
        unset(
            $qQuery['submit'],
            $qQuery['limit'],
            $qQuery['offset'],
            $qQuery['page'],
            $qQuery['per_page'],
            $qQuery['sort_by'],
            $qQuery['sort_order'],
            $qQuery['sort_by_default'],
            $qQuery['sort_order_default']
        );

        $form = new \Laminas\Form\Form();
        $form
            ->setAttribute('method', 'get')
            ->add([
                'name' => 'id',
                'type' => \Omeka\Form\Element\ResourceTemplateSelect::class,
                'options' => [
                    'prepend_value_options' => [
                        'all' => 'All', // @translate
                    ],
                    'empty_option' => '',
                ],
                'attributes' => [
                    'multiple' => true,
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select resource templates', // @translate
                ],
            ])
            ->add([
                'name' => 'submit',
                'type' => 'submit',
                'attributes' => [
                    'value' => 'Submit',
                ],
            ]);

        // TODO Check why resourceTemplateSelect is not constructed.
        // Avoid an issue with bad init of select.
        $services = $this->api()->read('vocabularies', ['id' => 1])->getContent()->getServiceLocator();
        $apiManager = $services->get('Omeka\ApiManager');
        $form->get('id')->setApiManager($apiManager);

        $form->setData($query);

        // Output the four most used templates by default.
        // Don't output any result when the form is invalid.
        if (!$qQuery) {
            // Api "returnScalar" does not manage search by item count.
            $query = [
                'sort_by' => 'item_count',
                'sort_order' => 'desc',
                'limit' => 4,
            ];
        } elseif ($qQuery && !$form->isValid()) {
            $query['id'] = [-1];
        } else {
            $ids = $query['id'] ?? [];
            if (!$ids) {
                $query['id'] = [-1];
            } elseif (in_array('all', $ids)) {
                unset($query['id']);
            }
        }

        // No limit except asked.
        // Anyway, there are never more than some templates.
        $query['per_page'] ??= 1000;

        // TODO No check of the form.
        $response = $this->api()->search('resource_templates', $query);
        $this->paginator($response->getTotalResults());
        $resourceTemplates = $response->getContent();

        return new ViewModel([
            'resourceTemplates' => $resourceTemplates,
            'form' => $form,
        ]);
    }

    /**
     * Remove useless keys "data_types" from o:data before final step.
     *
     * Add keys data_type_name and data_type_label to avoid notice in core view.
     *
     * {@inheritDoc}
     * @see \Omeka\Controller\Admin\ResourceTemplateController::importAction()
     */
    public function importAction()
    {
        if (!$this->getRequest()->isPost()) {
            return parent::importAction();
        }

        // Process import form.
        $file = $this->params()->fromFiles('file');
        if ($file) {
            // To avoid duplication of code, the csv/tsv file is converted into
            // a json input.
            $result = $this->prepareImportFile($file);
            if ($result) {
                return parent::importAction();
            }
            return new ViewModel([
                'form' => $this->getForm(ResourceTemplateImportForm::class),
            ]);
        }

        $form = $this->getForm(ResourceTemplateReviewImportForm::class);
        $data = $this->params()->fromPost();
        $form->setData($data);
        if (!$form->isValid()) {
            return parent::importAction();
        }

        // Process review import form.
        $import = json_decode($form->getData()['import'], true);
        $import['o:label'] = $this->params()->fromPost('label');

        $dataTypes = $this->params()->fromPost('data_types') ?? [];
        foreach ($dataTypes as $key => $dataTypeList) {
            $import['o:resource_template_property'][$key]['o:data_type'] = $dataTypeList;
        }

        // Clean the form when duplicate properties.
        foreach ($import['o:resource_template_property'] as $key => $rtp) {
            unset($import['o:resource_template_property'][$key]['vocabulary_namespace_uri']);
            unset($import['o:resource_template_property'][$key]['vocabulary_label']);
            unset($import['o:resource_template_property'][$key]['local_name']);
            unset($import['o:resource_template_property'][$key]['label']);
            unset($import['o:resource_template_property'][$key]['vocabulary_prefix']);
            unset($import['o:resource_template_property'][$key]['data_type_label']);
            $rtp = $import['o:resource_template_property'][$key];
            // Check duplicate properties when there are multiple data types.
            if (isset($dataTypes[$key]) && count($dataTypes[$key]) > 1
                && isset($rtp['o:data_type']) && count($rtp['o:data_type']) > 1
                && isset($rtp['o:data']) && count($rtp['o:data']) > 1
            ) {
                // Reorder the data types according to original content, not the
                // form element select, and fill each sub-data with the right
                // data types.
                $dataTypeListOriginal = $rtp['data_types'];
                $rtp['o:data_type'] = array_unique($rtp['o:data_type']);
                $dataTypeListNew = array_combine($rtp['o:data_type'], $rtp['o:data_type']);
                $dataTypeListNewOrdered = [];
                foreach ($dataTypeListOriginal as $dataTypeOriginal => $dataTypeOriginalData) {
                    if (isset($dataTypeListNew[$dataTypeOriginal])) {
                        $dataTypeListNewOrdered[$dataTypeOriginal] = $dataTypeOriginalData;
                        unset($dataTypeListNew[$dataTypeOriginal]);
                    }
                    // Else it is a custom vocab, whose id may be different, so
                    // take them in order. It will be improved with new form.
                    // In most of the real use cases, it is enough anyway.
                    // Or the user modified the list, and it's not managed.
                    else {
                        foreach ($dataTypeListNew as $dataTypeNew) {
                            if (strtok($dataTypeNew, ':') === 'customvocab') {
                                $dataTypeListNewOrdered[$dataTypeNew] = ['name' => $dataTypeNew, 'label' => $dataTypeNew];
                                unset($dataTypeListNew[$dataTypeNew]);
                                break;
                            }
                        }
                    }
                }
                // Keep remaining new data types.
                $dataTypeListNewOrdered = array_merge($dataTypeListNewOrdered, $dataTypeListNew);
                $import['o:resource_template_property'][$key]['data_types'] = $dataTypeListNewOrdered;
                $import['o:resource_template_property'][$key]['o:data_type'] = array_keys($dataTypeListNewOrdered);
                // Separate the data types by rtp data.
                $count = count($rtp['o:data']);
                foreach (array_keys($rtp['o:data']) as $k) {
                    --$count;
                    if ($count) {
                        if (count($dataTypeListNewOrdered)) {
                            $first = array_shift($dataTypeListNewOrdered);
                            $rtpDataDataType = [$first['name']];
                        } else {
                            $rtpDataDataType = [];
                        }
                        $import['o:resource_template_property'][$key]['o:data'][$k]['o:data_type'] = $rtpDataDataType;
                    } else {
                        $import['o:resource_template_property'][$key]['o:data'][$k]['o:data_type'] = array_keys($dataTypeListNewOrdered);
                    }
                }
            } else {
                $import['o:resource_template_property'][$key]['o:data'][0] = $rtp['o:data'][0] ?? $rtp;
                $import['o:resource_template_property'][$key]['o:data'][0]['o:data_type'] = $import['o:resource_template_property'][$key]['o:data_type']
                    ?? $import['o:resource_template_property'][$key]['data_types'];
            }
            unset($import['o:resource_template_property'][$key]['o:data'][0]['o:data']);
            foreach (array_keys($rtp['o:data']) as $k) {
                unset($import['o:resource_template_property'][$key]['o:data'][$k]['data_types']);
            }

            // Keep default language when importing from Omeka S < v4.
            if (isset($rtp['o:data']) && count($rtp['o:data']) > 1) {
                foreach ($rtp['o:data'] as $k => $rtpOData) {
                    if (!empty($rtpOData['default_language'])) {
                        $import['o:resource_template_property'][$key]['o:default_lang'] = $rtpOData['default_language'];
                    }
                    unset($import['o:resource_template_property'][$key]['o:data'][$k]['default_language']);
                }
            }
        }

        $import['o:resource_class'] = empty($import['o:resource_class']['o:id']) ? null : ['o:id' => $import['o:resource_class']['o:id']];
        $import['o:title_property'] = empty($import['o:title_property']['o:id']) ? null : ['o:id' => $import['o:title_property']['o:id']];
        $import['o:description_property'] = empty($import['o:description_property']['o:id']) ? null : ['o:id' => $import['o:description_property']['o:id']];

        $response = $this->api($form)->create('resource_templates', $import);
        if ($response) {
            return $this->redirect()->toUrl($response->getContent()->url());
        }

        return parent::importAction();
    }

    /**
     * Same as parent, except check of data types for duplicated properties
     * inside o:data, and import of common modules data types.
     *
     * {@inheritDoc}
     * @see \Omeka\Controller\Admin\ResourceTemplateController::flagValid()
     */
    protected function flagValid(array $import)
    {
        $getVocab = function ($namespaceUri): ?VocabularyRepresentation {
            try {
                return $this->api()->read('vocabularies', ['namespaceUri' => $namespaceUri])->getContent();
            } catch (\Exception $e) {
                return null;
            }
        };

        $getDataTypesByName = function ($dataTypesNameLabels) {
            $result = [];
            foreach ($dataTypesNameLabels as $dataType) {
                $result[$dataType['name']] = $dataType;
            }
            return $result;
        };

        // Manage core data types and common modules ones.
        $getKnownDataType = function ($dataTypeNameLabel): ?string {
            if (in_array($dataTypeNameLabel['name'], [
                'literal',
                'resource',
                'resource:item',
                'resource:itemset',
                'resource:media',
                'uri',
                // DataTypeGeometry
                'geography',
                'geometry',
                'geography:coordinates',
                'geometry:coordinates',
                'geometry:position',
                // DataTypeRdf.
                'boolean',
                'html',
                'xml',
                // DataTypePlace.
                'place',
                // NumericDataTypes
                'numeric:timestamp',
                'numeric:integer',
                'numeric:duration',
                'numeric:interval',
            ])
                || mb_substr((string) $dataTypeNameLabel['name'], 0, 13) === 'valuesuggest:'
                || mb_substr((string) $dataTypeNameLabel['name'], 0, 16) === 'valuesuggestall:'
            ) {
                return $dataTypeNameLabel['name'];
            }

            if (mb_substr((string) $dataTypeNameLabel['name'], 0, 12) === 'customvocab:') {
                try {
                    $customVocab = $this->api()->read('custom_vocabs', ['label' => $dataTypeNameLabel['label']])->getContent();
                    return 'customvocab:' . $customVocab->id();
                } catch (\Omeka\Api\Exception\NotFoundException $e) {
                    return null;
                }
            }
            return null;
        };

        if (isset($import['o:resource_class'])) {
            if ($vocab = $getVocab($import['o:resource_class']['vocabulary_namespace_uri'])) {
                $import['o:resource_class']['vocabulary_prefix'] = $vocab->prefix();
                try {
                    $class = $this->api()->read('resource_classes', [
                        'vocabulary' => $vocab->id(),
                        'localName' => $import['o:resource_class']['local_name'],
                    ])->getContent();
                } catch (\Exception $e) {
                    $class = null;
                }
                if ($class) {
                    $import['o:resource_class']['o:id'] = $class->id();
                }
            }
        }

        foreach (['o:title_property', 'o:description_property'] as $property) {
            if (isset($import[$property])) {
                if ($vocab = $getVocab($import[$property]['vocabulary_namespace_uri'])) {
                    $import[$property]['vocabulary_prefix'] = $vocab->prefix();
                    try {
                        $prop = $this->api()->read('properties', [
                            'vocabulary' => $vocab->id(),
                            'localName' => $import[$property]['local_name'],
                        ])->getContent();
                    } catch (\Exception $e) {
                        $prop = null;
                    }
                    if ($prop) {
                        $import[$property]['o:id'] = $prop->id();
                    }
                }
            }
        }

        foreach ($import['o:resource_template_property'] as $key => $property) {
            if ($vocab = $getVocab($property['vocabulary_namespace_uri'])) {
                $import['o:resource_template_property'][$key]['vocabulary_prefix'] = $vocab->prefix();
                try {
                    $prop = $this->api()->read('properties', [
                        'vocabulary' => $vocab->id(),
                        'localName' => $import[$property]['local_name'],
                    ])->getContent();
                } catch (\Exception $e) {
                    $prop = null;
                }
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
                    unset($import['o:resource_template_property'][$key]['data_type_name']);
                    unset($import['o:resource_template_property'][$key]['data_type_label']);
                    $import['o:resource_template_property'][$key]['data_types'] = $getDataTypesByName($import['o:resource_template_property'][$key]['data_types']);
                    // Prepare the list of standard data types.
                    $import['o:resource_template_property'][$key]['o:data_type'] = [];
                    foreach ($import['o:resource_template_property'][$key]['data_types'] as $name => $dataTypeNameLabel) {
                        $known = $getKnownDataType($dataTypeNameLabel);
                        if ($known) {
                            $import['o:resource_template_property'][$key]['o:data_type'][] = $known;
                            $import['o:resource_template_property'][$key]['data_types'][$name]['name'] = $known;
                        }
                    }
                    $import['o:resource_template_property'][$key]['o:data_type'] = array_unique($import['o:resource_template_property'][$key]['o:data_type']);
                    // Prepare the list of standard data types for duplicated
                    // properties (only one most of the time, that is the main).
                    $import['o:resource_template_property'][$key]['o:data'] = array_values($import['o:resource_template_property'][$key]['o:data'] ?? []);
                    $import['o:resource_template_property'][$key]['o:data'][0]['data_types'] = $import['o:resource_template_property'][$key]['data_types'];
                    $import['o:resource_template_property'][$key]['o:data'][0]['o:data_type'] = $import['o:resource_template_property'][$key]['o:data_type'];
                    $first = true;
                    foreach ($import['o:resource_template_property'][$key]['o:data'] as $k => $rtpData) {
                        if ($first) {
                            $first = false;
                            continue;
                        }
                        // Prepare the list of standard data types if any.
                        $import['o:resource_template_property'][$key]['o:data'][$k]['o:data_type'] = [];
                        if (empty($rtpData['data_types'])) {
                            continue;
                        }
                        $import['o:resource_template_property'][$key]['o:data'][$k]['data_types'] = $getDataTypesByName($import['o:resource_template_property'][$key]['o:data'][$k]['data_types']);
                        foreach ($import['o:resource_template_property'][$key]['o:data'][$k]['data_types'] as $name => $dataTypeNameLabel) {
                            $known = $getKnownDataType($dataTypeNameLabel);
                            if ($known) {
                                $import['o:resource_template_property'][$key]['o:data'][$k]['o:data_type'][] = $known;
                                $import['o:resource_template_property'][$key]['o:data'][$k]['data_types'][$name]['name'] = $known;
                            }
                        }
                        $import['o:resource_template_property'][$key]['o:data'][$k]['o:data_type'] = array_unique($import['o:resource_template_property'][$key]['o:data'][$k]['o:data_type']);
                    }
                }
            }
            // TODO Remove this fix, that avoids a notice in core, waiting for fix merge (3.1?).
            if (empty($import['o:resource_template_property'][$key]['data_type_label'])) {
                if (empty($import['o:resource_template_property'][$key]['data_types'])) {
                    $import['o:resource_template_property'][$key]['data_type_label'] = '';
                } else {
                    $label = reset($import['o:resource_template_property'][$key]['data_types']);
                    $import['o:resource_template_property'][$key]['data_type_label'] = empty($label['label']) ? '' : $label['label'];
                }
            }
        }

        return $import;
    }

    /**
     * Convert a csv/tsv import into a json template.
     *
     * @todo Instead of a conversion into json, redirect data into the standard form?
     */
    protected function prepareImportFile(array $fileData): bool
    {
        // In such a case, return to the parent check.
        if (!$fileData['size'] || $fileData['error'] || !$fileData['size']) {
            return true;
        }

        if ($fileData['type'] === 'application/json') {
            $hasDuplicate = false;
            $content = json_decode(file_get_contents($fileData['tmp_name']), true) ?: [];
            foreach ($content['o:resource_template_property'] ?? [] as $rtp) {
                if (count($rtp['o:data'] ?? []) > 1) {
                    $hasDuplicate = true;
                    break;
                }
            }
            if ($hasDuplicate) {
                $this->messenger()->addWarning(
                    'The template has duplicate properties. They are mixed in the form, but will be imported separately.' // @translate
                );
            }
            return true;
        }

        $checkedFileData = $this->checkFile($fileData);
        if (!$checkedFileData) {
            $this->messenger()->addError(new PsrMessage(
                'Wrong media type "{media_type}" for file.', // @translate
                ['media_type' => $fileData['type']]
            ));
            return false;
        }

        $fileData = $checkedFileData;

        if ($fileData['type'] === 'text/tab-separated-values') {
            $options = [
                'type' => $fileData['type'],
                'delimiter' => "\t",
                'enclosure' => chr(0),
            ];
        } else {
            $options = [
                'type' => $fileData['type'],
                'delimiter' => ',',
                'enclosure' => '"',
            ];
        }
        $rows = $this->extractRows($fileData['tmp_name'], $options);
        if (empty($rows)) {
            $this->messenger()->addError(
                'The file does not contain any row.' // @translate
            );
            return false;
        }

        // Define some specific functions.

        $stringToArray = function ($v) {
            $v = (string) $v;
            return strlen(trim($v))
                ? array_map('trim', explode('|', $v))
                : [];
        };
        $cellValue = function ($v, $k) {
            // A standard option manageable in form is never a null value.
            if (trim((string) $v) === 'null') {
                return 'null';
            }
            if (mb_substr($k, 0, 19) === 'Template data list:' || mb_substr($k, 0, 19) === 'Template data list:') {
                return array_filter(array_map('trim', explode('|', $v)), 'strlen') ?: [];
            }
            if ($v === '' || $v === null || $v === []) {
                return null;
            }
            // Note: some values are json encoded in the form (autofiller), but
            // in that case, there is a double encoding.
            return json_decode($v, true);
        };
        $templateData = function ($v, $k) use ($cellValue) {
            // Don't check ":" because some spreadsheets add a space before it.
            return mb_substr((string) $k, 0, 13) === 'Template data'
                && $cellValue($v, $k) !== null;
        };
        $propertyData = function ($v, $k) use ($cellValue) {
            // Don't check ":" because some spreadsheets add a space before it.
            return mb_substr((string) $k, 0, 13) === 'Property data'
                && $cellValue($v, $k) !== null;
        };
        // Is empty except values like "0".
        $isEmpty = fn ($v) => is_array($v) ? !count($v) : !strlen((string) $v);

        // Convert to json.
        $json = [];
        $rtpTerms = [];
        foreach ($rows as $row) {
            switch ($row['Type']) {
                case 'Template':
                    // Add default keys.
                    $row += [
                        'Template label' => null,
                        'Resource class' => null,
                        'Title property' => null,
                        'Description property' => null,
                    ];
                    $json['o:label'] = $row['Template label'] ?: '[Untitled]';
                    $json['o:resource_class'] = $this->fillTerm($row['Resource class'], 'resource_classes');
                    $json['o:title_property'] = $this->fillTerm($row['Title property']);
                    $json['o:description_property'] = $this->fillTerm($row['Description property']);
                    foreach (array_filter($row, $templateData, ARRAY_FILTER_USE_BOTH) as $header => $cell) {
                        $cell = $cellValue($cell, $header);
                        if ($isEmpty($cell)) {
                            continue;
                        }
                        $json['o:data'][trim(mb_substr($header, mb_strpos($header, ':') + 1))] = $cell;
                    }
                    break;
                case 'Property':
                    $row += [
                        'Property' => null,
                        'Alternate label' => null,
                        'Alternate comment' => null,
                        'Data types' => null,
                        'Required' => null,
                        'Private' => null,
                        'Default language' => null,
                    ];
                    $rtp = $this->fillTerm($row['Property']);
                    if (!$rtp) {
                        break;
                    }
                    $rtp['o:alternate_label'] = $row['Alternate label'] ?: '';
                    $rtp['o:alternate_comment'] = $row['Alternate comment'] ?: '';
                    $rtp['data_types'] = array_filter(array_map(fn ($v) => $v ? ['name' => $v, 'label' => $v] : null, $stringToArray($row['Data types'])));
                    $rtp['o:is_required'] = (bool) $row['Required'];
                    $rtp['o:is_private'] = (bool) $row['Private'];
                    $rtp['o:default_lang'] = $row['Default language'];
                    $rtpOData = [];
                    foreach (array_filter($row, $propertyData, ARRAY_FILTER_USE_BOTH) as $header => $cell) {
                        $cell = $cellValue($cell, $header);
                        if ($isEmpty($cell)) {
                            continue;
                        }
                        $rtpOData[trim(mb_substr($header, mb_strpos($header, ':') + 1))] = $cell;
                    }
                    $rtp['o:data'] = [$rtpOData];
                    unset($rtp['data_type_label']);
                    $json['o:resource_template_property'][] = $rtp;
                    $rtpTerms[$rtp['term']] = isset($rtpTerms[$rtp['term']]) ? ++$rtpTerms[$rtp['term']] : 1;
                    break;
                default:
                    break;
            }
        }

        if (empty($json['o:resource_template_property'])) {
            $this->messenger()->addError(
                'There is no valid template properties.' // @translate
            );
            return false;
        }

        $skips = [
            'vocabulary_namespace_uri' => null,
            'vocabulary_label' => null,
            'local_name' => null,
            'label' => null,
            'vocabulary_prefix' => null,
            'is-title-property' => null,
            'is-description-property' => null,
            'o:property' => null,
            'o:data_type' => null,
            'o:data' => null,
            'data_type_label' => null,
        ];

        // TODO Improve review form for duplicate properties (don't mix them here, but after the form or before or during hydration).
        // Merge duplicate properties according to this module that allows them.
        $hasDuplicate = false;
        foreach ($rtpTerms as $term => $count) {
            if ($count <= 1) {
                continue;
            }
            $hasDuplicate = true;
            $first = true;
            $firstRtp = null;
            foreach ($json['o:resource_template_property'] as $key => $rtp) {
                if ($rtp['term'] !== $term) {
                    continue;
                }
                // Normalize a key for next checks.
                $rtp['o:alternate_label'] = empty($rtp['o:alternate_label']) ? null : $rtp['o:alternate_label'];
                if ($first) {
                    $first = false;
                    $firstKey = $key;
                    $firstRtp = $rtp;
                    $firstRtp['o:data'] = [];
                } else {
                    // No duplicate label in case of a duplicate property.
                    // TODO Check with all duplicates.
                    if ($firstRtp['o:alternate_label'] === $rtp['o:alternate_label']) {
                        $this->messenger()->addError(sprintf(
                            'The alternative label for a duplicate property (%s) should be unique.', // @translate
                            $term
                        ));
                        return false;
                    }
                    unset($json['o:resource_template_property'][$key]);
                }
                $data = array_diff_key($rtp, $skips);
                $data['o:data'] = reset($rtp['o:data']) ? array_diff_key(reset($rtp['o:data']), $skips) : [];
                $firstRtp['o:data'][] = $data;
            }
            $json['o:resource_template_property'][$firstKey] = $firstRtp;
        }
        $json['o:resource_template_property'] = array_values($json['o:resource_template_property']);

        $fileData['name'] .= '.json';
        $fileData['type'] = 'application/json';
        $fileData['size'] = file_put_contents($fileData['tmp_name'], json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        if (!$fileData['size']) {
            $fileData['error'] = 1;
            $this->messenger()->addError(
                'File cannot be checked or saved.' // @translate
            );
            return false;
        }

        if ($hasDuplicate) {
            $this->messenger()->addWarning(
                'The template has duplicate properties. They are mixed in the form, but will be imported separately.' // @translate
            );
        }

        return true;
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
            $oldExportBefore3 = !array_key_exists('data_types', $property);

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
            if ($oldExportBefore3
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
            if ($oldExportBefore3) {
                if ((!is_string($property['data_type_name']) && !is_null($property['data_type_name']))
                    || (!is_string($property['data_type_label']) && !is_null($property['data_type_label']))
                ) {
                    return false;
                }
            } elseif (!is_array($property['data_types']) && !is_null($property['data_types'])) {
                return false;
            }

            // Validate info introduced in newer versions.
            if (isset($property['o:default_lang']) && (!is_string($property['o:default_lang']) && !is_null($property['o:default_lang']))) {
                // o:default_lang introduced in v4.0.0
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
        $output = $this->params()->fromQuery('output', 'json');
        switch ($output) {
            case 'csv':
                return $this->exportCsv('csv');
            case 'tsv':
                return $this->exportCsv('tsv');
            case 'json':
            default:
                return $this->exportJson();
        }
    }

    protected function exportJson(): \Laminas\Stdlib\ResponseInterface
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

        /** @var \AdvancedResourceTemplate\Api\Representation\ResourceTemplatePropertyRepresentation $templateProperty */
        foreach ($templateProperties as $templateProperty) {
            $property = $templateProperty->property();
            $vocab = $property->vocabulary();

            // Note that "position" is implied by array order.
            $exportRtp = [
                'o:alternate_label' => $templateProperty->alternateLabel(),
                'o:alternate_comment' => $templateProperty->alternateComment(),
                'o:is_required' => $templateProperty->isRequired(),
                'o:is_private' => $templateProperty->isPrivate(),
                'o:default_lang' => $templateProperty->defaultLang(),
                'o:data' => $templateProperty->data(),
                // The labels are needed for custom vocabs.
                'data_types' => $templateProperty->dataTypeLabels(),
                'vocabulary_namespace_uri' => $vocab->namespaceUri(),
                'vocabulary_label' => $vocab->label(),
                'local_name' => $property->localName(),
                'label' => $property->label(),
            ];
            // The data types should be prepared for each sub-data too.
            /** @var \AdvancedResourceTemplate\Api\Representation\ResourceTemplatePropertyDataRepresentation $rtpData */
            foreach ($exportRtp['o:data'] as $k => $rtpData) {
                // TODO Don't use json_decode(json_encode()).
                $exportRtp['o:data'][$k] = json_decode(json_encode($rtpData), true);
                $exportRtp['o:data'][$k]['data_types'] = $rtpData->dataTypeLabels();
                unset($exportRtp['o:data'][$k]['o:data_type']);
            }
            $export['o:resource_template_property'][] = $exportRtp;
        }

        $filename = $this->slugify($template->label());
        $export = json_encode($export, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $response = $this->getResponse();
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Content-Type', 'application/json')
                ->addHeaderLine('Content-Disposition', sprintf('attachment; filename="%s.json"', $filename))
                // Don't use mb_strlen.
                ->addHeaderLine('Content-Length', strlen($export));
        $response->setHeaders($headers);
        $response->setContent($export);
        return $response;
    }

    /**
     * @param string $type May be "csv" or "tsv".
     */
    protected function exportCsv(string $type): \Laminas\Stdlib\ResponseInterface
    {
        /** @var \AdvancedResourceTemplate\Api\Representation\ResourceTemplateRepresentation $template */
        $template = $this->api()->read('resource_templates', $this->params('id'))->getContent();

        $isFlatArray = fn (array $v) => !array_filter($v, fn ($vv) => !is_scalar($vv));

        $templateHeaders = [
            'Type',
            'Template label',
            'Resource class',
            'Title property',
            'Description property',
        ];
        $templateHeaders = array_combine($templateHeaders, $templateHeaders);
        $templatePropertyHeaders = [
            'Property',
            'Alternate label',
            'Alternate comment',
            'Data types',
            'Required',
            'Private',
            'Default language',
        ];
        $templatePropertyHeaders = array_combine($templatePropertyHeaders, $templatePropertyHeaders);

        $templateProperties = $template->resourceTemplateProperties();

        // Prepare the headers, so loop all datas.

        // Prepare the headers for the template data.
        // Manage flat arrays (multiselect) as list to allow to separate them
        // from scalar values during import.
        $templateDataHeaders = $template->data();
        array_walk($templateDataHeaders, function (&$v, $k) use ($isFlatArray): void {
            $v = is_array($v) && count($v) && $isFlatArray($v) && json_encode($v) === json_encode(array_values($v))
                ? 'Template data list: ' . $k
                : 'Template data: ' . $k;
        });
        // If a key is a list and a scalar, keep list only.
        foreach ($templateDataHeaders as $k => $v) {
            if (mb_substr($v, 0, 14) === 'Template data:' && in_array('Template data list: ' . $k, $templateDataHeaders)) {
                unset($templateDataHeaders[$k]);
            }
        }
        $templateDataHeaders = array_combine($templateDataHeaders, $templateDataHeaders);

        // Prepare the headers for the properties data.
        $templatePropertyDataHeaders = [];
        foreach ($templateProperties as $templateProperty) foreach ($templateProperty->data() as $rtpData) {
            $rtpDataVal = $rtpData->data();
            array_walk($rtpDataVal, function (&$v, $k) use ($isFlatArray): void {
                $v = is_array($v) && count($v) && $isFlatArray($v) && json_encode($v) === json_encode(array_values($v))
                    ? 'Property data list: ' . $k
                    : 'Property data: ' . $k;
            });
            $templatePropertyDataHeaders = array_replace($templatePropertyDataHeaders, array_combine($rtpDataVal, $rtpDataVal));
        }
        foreach ($templatePropertyDataHeaders as $k => $v) {
            if (mb_substr($v, 0, 14) === 'Property data:' && in_array('Property data list: ' . $k, $templatePropertyDataHeaders)) {
                unset($templatePropertyDataHeaders[$k]);
            }
        }
        $skips = [
            'Property data: o:alternate_label',
            'Property data: o:alternate_comment',
            'Property data: is-title-property',
            'Property data: is-description-property',
            'Property data: o:is_required',
            'Property data: o:is_private',
            'Property data: o:default_lang',
            'Property data: o:data_type',
        ];
        $templatePropertyDataHeaders = array_diff($templatePropertyDataHeaders, $skips);

        $headers = array_replace(
            $templateHeaders,
            $templateDataHeaders,
            $templatePropertyHeaders,
            $templatePropertyDataHeaders
        );

        // Because the output is always small, create it in memory in realtime.
        $stream = fopen('php://temp', 'w+');

        // Prepend the utf-8 bom to support Windows.
        fwrite($stream, chr(0xEF) . chr(0xBB) . chr(0xBF));

        $this->appendCsvRow($stream, $headers, $type);
        // Template.
        $templateClass = $template->resourceClass();
        $templateTitle = $template->titleProperty();
        $templateDescription = $template->descriptionProperty();

        $row = array_fill_keys($headers, null);
        $row['Type'] = 'Template';
        $row['Template label'] = $template->label();
        $row['Resource class'] = $templateClass ? $templateClass->term() : null;
        $row['Title property'] = $templateTitle ? $templateTitle->term() : null;
        $row['Description property'] = $templateDescription ? $templateDescription->term() : null;
        foreach ($template->data() as $key => $value) {
            if ($value === '' || $value === null || $value === []) {
                continue;
            }
            if (isset($templateDataHeaders['Template data list: ' . $key])) {
                // For flat array, use a string separated by "|".
                $row['Template data list: ' . $key] = implode(' | ', $value);
            } else {
                // In all other cases (scalar, objects), json encode value.
                $row['Template data: ' . $key] = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }

        $this->appendCsvRow($stream, $row, $type);

        // Properties.
        /** @var \AdvancedResourceTemplate\Api\Representation\ResourceTemplatePropertyRepresentation $templateProperty */
        foreach ($templateProperties as $templateProperty) {
            $propertyRow = array_fill_keys($headers, null);
            $propertyRow['Type'] = 'Property';
            $propertyRow['Property'] = $templateProperty->property()->term();
            foreach ($templateProperty->data() as $rtpData) {
                $row = $propertyRow;
                $row['Alternate label'] = $rtpData->alternateLabel();
                $row['Alternate comment'] = $rtpData->alternateComment();
                $row['Data types'] = implode(' | ', $rtpData->dataTypes());
                $row['Required'] = $rtpData->isRequired() ? '1' : '0';
                $row['Private'] = $rtpData->isPrivate() ? '1' : '0';
                $row['Default language'] = $rtpData->defaultLang();
                foreach ($rtpData->data() as $key => $value) {
                    if (in_array('Property data: ' . $key, $skips)) {
                        continue;
                    }
                    if ($value === '' || $value === null || $value === []) {
                        continue;
                    }
                    if (isset($templateDataHeaders['Property data list: ' . $key])) {
                        // For flat array, use a string separated by "|".
                        $row['Property data list: ' . $key] = implode(' | ', $value);
                    } else {
                        // In all other cases (scalar, objects), json encode value.
                        $row['Property data: ' . $key] = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    }
                }
                $this->appendCsvRow($stream, $row, $type);
            }
        }

        rewind($stream);
        $export = stream_get_contents($stream);
        fclose($stream);

        $filename = $this->slugify($template->label());

        $response = $this->getResponse();
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Content-Type', $type === 'tsv' ? 'text/tab-separated-values' : 'text/csv')
            ->addHeaderLine('Content-Disposition', sprintf('attachment; filename="%s.%s"', $filename, $type))
            // Don't use mb_strlen.
            ->addHeaderLine('Content-Length', strlen($export));
        $response->setHeaders($headers);
        $response->setContent($export);
        return $response;
    }

    /**
     * Transform the given string into a valid filename
     */
    protected function slugify(string $input): string
    {
        if (extension_loaded('intl')) {
            $transliterator = \Transliterator::createFromRules(':: NFD; :: [:Nonspacing Mark:] Remove; :: NFC;');
            $slug = $transliterator->transliterate($input);
        } elseif (extension_loaded('iconv')) {
            $slug = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $input);
        } else {
            $slug = $input;
        }
        $slug = mb_strtolower($slug, 'UTF-8');
        $slug = preg_replace('/[^a-z0-9-]+/u', '_', $slug);
        $slug = preg_replace('/-{2,}/', '_', $slug);
        $slug = preg_replace('/-*$/', '', $slug);
        return $slug;
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
                // TODO Don't use json_decode(json_encode()).
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

            if (isset($post['_post'])) {
                $post = json_decode($post['_post'], true) ?: [];
            }

            // Maybe useless.
            /** @see https://github.com/omeka/omeka-s/commit/8390c44e39269dda022ac5076e49fe7a34c99a6b */
            // Must include the o:resource_template_property key if all
            // properties are removed, else nothing is removed.
            $post['o:resource_template_property'] ??= [];

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
                            // Upstream message.
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
        // Must include the o:resource_template_property key if all properties
        // are removed, else nothing is removed.
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

        // Allow to select multiple resource classes, so use the data option.
        if (empty($data['o:data']['suggested_resource_class_ids'])) {
            $data['o:resource_class'] = empty($data['o:resource_class']) ? [] : [$data['o:resource_class']];
        } else {
            $data['o:resource_class'] = $data['o:data']['suggested_resource_class_ids'];
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
        // Allow to select multiples classes, but only the first one can be
        // saved directly in template.
        // Check compatibility with standard form too.
        if (empty($post['o:resource_class'])) {
            $post['o:data']['suggested_resource_class_ids'] = [];
        } elseif (is_array($post['o:resource_class'])) {
            $post['o:data']['suggested_resource_class_ids'] = array_map('intval', $post['o:resource_class']);
        } else {
            $post['o:data']['suggested_resource_class_ids'] = [(int) $post['o:resource_class']];
        }
        $post['o:data']['suggested_resource_class_ids'] = array_filter($post['o:data']['suggested_resource_class_ids']);

        // To simplify validation, store suggested resource classes as terms.
        if ($post['o:data']['suggested_resource_class_ids']) {
            $result = [];
            foreach ($this->api()->search('resource_classes', ['id' => array_values($post['o:data']['suggested_resource_class_ids'])], ['initialize' => false])->getContent() as $class) {
                $result[$class->term()] = $class->id();
            }
            $post['o:data']['suggested_resource_class_ids'] = $result;
        }
        $post['o:resource_class'] = reset($post['o:data']['suggested_resource_class_ids']) ?: null;

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
            // TODO Fix issue with property id id with form, but should be fixed when form is filled with bad data or when module is upgraded?
            if (is_array($rtp['o:property']['o:id'])) {
                $rtp['o:property']['o:id'] = $rtp['o:property']['o:id']['o:id'];
            }
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

        $defaultProperties = [
            'dcterms:title' => 'title',
            'dcterms:description' => 'description',
        ];
        foreach ($defaultProperties as $property) {
            $property = $this->api()->read('properties', ['vocabulary' => 1, 'localName' => $property])->getContent();
            // In a Collection, "false" is not allowed for a checkbox, etc, except with input filter.
            $resourceTemplate['o:resource_template_property'][] = [
                'o:property' => ['o:id' => $property->id()],
                'o:alternate_label' => '',
                'o:alternate_comment' => '',
                'o:data_type' => [],
                'o:is_required' => 0,
                'o:is_private' => 0,
                'o:default_lang' => '',
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

        $namePrefix = 'o:resource_template_property[' . random_int((int) (PHP_INT_MAX / 1000000000), (int) (PHP_INT_MAX / 100000000)) . ']';
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

    protected function appendCsvRow($stream, array $fields, string $type = 'csv'): void
    {
        $type === 'tsv'
            ? fputcsv($stream, $fields, "\t", chr(0), chr(0))
            : fputcsv($stream, $fields);
    }

    /**
     * Get an array from a csv/tsv file. The headers are returned as first row.
     */
    protected function extractRows(string $filepath, array $options = []): array
    {
        $options += [
            'type' => 'text/csv',
            'delimiter' => ',',
            'enclosure' => '"',
        ];
        if ($options['type'] === 'text/tab-separated-values') {
            $options['delimiter'] = "\t";
        }
        $separator = $options['delimiter'];
        $enclosure = $options['enclosure'];

        // fgetcsv is not used to avoid issues with bom.
        $content = file_get_contents($filepath);
        $content = mb_convert_encoding($content, 'UTF-8');
        if (substr($content, 0, 3) === chr(0xEF) . chr(0xBB) . chr(0xBF)) {
            $content = substr($content, 3);
        }
        if (empty($content)) {
            return [];
        }

        $first = true;
        $rows = array_map(fn ($v) => str_getcsv($v, $separator, $enclosure), array_map('trim', explode("\n", $content)));
        foreach ($rows as $key => $row) {
            if (empty(array_filter($row))) {
                unset($rows[$key]);
                continue;
            }
            // First row is headers.
            if ($first) {
                $first = false;
                $headers = array_combine($row, $row);
                $countHeaders = count($headers);
                // Headers should not be empty and duplicates are forbidden.
                if (!$countHeaders
                    || $countHeaders !== count($row)
                ) {
                    return [];
                }
                $rows[$key] = $headers;
                continue;
            }
            if (count($row) < $countHeaders) {
                $row = array_slice(array_merge($row, array_fill(0, $countHeaders, '')), 0, $countHeaders);
            } elseif (count($row) > $countHeaders) {
                $row = array_slice($row, 0, $countHeaders);
            }
            $rows[$key] = array_combine($headers, array_map('trim', $row));
        }

        $rows = array_values(array_filter($rows));
        if (!isset($rows[0]['Type'])) {
            return [];
        }

        return $rows;
    }

    /**
     * Check the file, according to its media type.
     *
     * @todo Use the class TempFile before.
     *
     * @param array $fileData
     *   File data from a post ($_FILES).
     */
    protected function checkFile(array $fileData): ?array
    {
        if (empty($fileData) || empty($fileData['tmp_name'])) {
            return null;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mediaType = $finfo->file($fileData['tmp_name']);
        $extension = strtolower(pathinfo($fileData['name'], PATHINFO_EXTENSION));
        $fileData['extension'] = $extension;

        // Fix old servers.
        if ($mediaType === 'application/csv') {
            $mediaType = 'text/csv';
        }
        // Some computers don't detect csv or tsv, so add excel too.
        elseif ($mediaType === 'application/vnd.ms-excel') {
            $fileData['type'] = strpos($mediaType, "\t") !== false ? 'text/tab-separated-values' : 'text/csv';
        }
        // Manage an exception for a very common format, undetected by fileinfo.
        elseif ($mediaType === 'text/plain' || $mediaType === 'application/octet-stream') {
            $extensions = [
                'txt' => 'text/plain',
                'csv' => 'text/csv',
                'tab' => 'text/tab-separated-values',
                'tsv' => 'text/tab-separated-values',
            ];
            if (isset($extensions[$extension])) {
                $mediaType = $extensions[$extension];
                $fileData['type'] = $mediaType;
            }
        }

        $supporteds = [
            // 'application/vnd.oasis.opendocument.spreadsheet' => true,
            'text/csv' => true,
            'text/plain' => true,
            'text/tab-separated-values' => true,
        ];
        if (!isset($supporteds[$mediaType])) {
            return null;
        }

        return $fileData;
    }

    protected function fillTerm(?string $term, string $type = 'properties'): ?array
    {
        if (empty($term)) {
            return null;
        }
        try {
            $vocabulary = $this->api()->read('vocabularies', ['prefix' => strtok($term, ':')])->getContent();
            $member = $this->api()->read($type, ['vocabulary' => $vocabulary->id(), 'localName' => strtok(':')])->getContent();
        } catch (\Exception $e) {
            return null;
        }
        return [
            'term' => $term,
            'vocabulary_namespace_uri' => $vocabulary->namespaceUri(),
            'vocabulary_label' => $vocabulary->label(),
            'local_name' => $member->localName(),
            'label' => $member->label(),
        ];
    }
}

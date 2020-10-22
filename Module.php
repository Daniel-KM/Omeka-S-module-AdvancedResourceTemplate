<?php declare(strict_types=1);

namespace AdvancedResourceTemplate;

if (!class_exists(\Generic\AbstractModule::class)) {
    require file_exists(dirname(__DIR__) . '/Generic/AbstractModule.php')
        ? dirname(__DIR__) . '/Generic/AbstractModule.php'
        : __DIR__ . '/src/Generic/AbstractModule.php';
}

use Generic\AbstractModule;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Form\Element;
use Laminas\Mvc\MvcEvent;
use Omeka\Form\Element\ArrayTextarea;

class Module extends AbstractModule
{
    const NAMESPACE = __NAMESPACE__;

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
            // The controller is only an alias.
            // ->allow(
            //     ['researcher', 'author', 'reviewer', 'editor'],
            //     ['AdvancedResourceTemplate\Controller\Admin\ResourceTemplate'],
            //     ['index', 'browse', 'show', 'show-details']
            // )
            // ->allow(
            //     ['author', 'editor'],
            //     ['AdvancedResourceTemplate\Controller\Admin\ResourceTemplate'],
            //     ['add-new-property-row', 'import', 'add', 'edit', 'delete', 'delete-confirm']
            // )
            ->allow(
                ['author', 'editor'],
                [
                    \AdvancedResourceTemplate\Entity\ResourceTemplateData::class,
                    \AdvancedResourceTemplate\Entity\ResourceTemplatePropertyData::class,
                ],
                ['create', 'update', 'delete']
            )
            // Specific rights for the controller.
            ->allow(
                $acl->getRoles(),
                ['AdvancedResourceTemplate\Controller\Admin\Index']
            );
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
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
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Annotation',
            'view.layout',
            [$this, 'addAdminResourceHeaders']
        );

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
            \Omeka\Form\ResourceTemplateForm::class,
            'form.add_elements',
            [$this, 'addResourceTemplateFormElements']
        );
        $sharedEventManager->attach(
            \Omeka\Form\ResourceTemplateForm::class,
            'form.add_input_filters',
            [$this, 'addResourceTemplateFormFilters']
        );
        $sharedEventManager->attach(
            \Omeka\Form\ResourceTemplatePropertyFieldset::class,
            'form.add_elements',
            [$this, 'addResourceTemplatePropertyFieldsetElements']
        );
    }

    public function addAdminResourceHeaders(Event $event): void
    {
        $view = $event->getTarget();

        $action = $view->params()->fromRoute('action');
        if (!in_array($action, ['add', 'edit'])) {
            return;
        }

        $isModal = $view->params()->fromQuery('window') === 'modal';
        if ($isModal) {
            $view->htmlElement('body')->appendAttribute('class', 'modal');
        }

        $assetUrl = $view->getHelperPluginManager()->get('assetUrl');
        $view->headLink()->appendStylesheet($assetUrl('css/advanced-resource-template-admin.css', 'AdvancedResourceTemplate'));
        $view->headScript()
            ->appendScript(sprintf('var baseUrl = %s;', json_encode($view->basePath('/'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)))
            ->appendFile($assetUrl('vendor/jquery-autocomplete/jquery.autocomplete.min.js', 'AdvancedResourceTemplate'), 'text/javascript', ['defer' => 'defer'])
            ->appendFile($assetUrl('js/advanced-resource-template-admin.js', 'AdvancedResourceTemplate'), 'text/javascript', ['defer' => 'defer']);
    }

    public function handleMainSettings(Event $event): void
    {
        parent::handleMainSettings($event);

        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');

        $autofillers = $settings->get('advancedresourcetemplate_autofillers') ?: [];
        $value = $this->autofillersToString($autofillers);

        $event
            ->getTarget()
            ->get('advancedresourcetemplate')
            ->get('advancedresourcetemplate_autofillers')
            ->setValue($value);
    }

    public function handleMainSettingsFilters(Event $event): void
    {
        $event->getParam('inputFilter')
            ->get('advancedresourcetemplate')
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

    public function addResourceTemplateFormElements(Event $event): void
    {
        $services = $this->getServiceLocator();
        $autofillers = [];
        foreach ($services->get('Omeka\Settings')->get('advancedresourcetemplate_autofillers', []) as $key => $value) {
            $autofillers[$key] = $value['label'] ?: $key;
        }

        /** @var \Omeka\Form\ResourceTemplateForm $form */
        $form = $event->getTarget();
        $form->get('o:data')
            ->add([
                'name' => 'autocomplete',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Autocomplete with existing values', // @translate
                    'value_options' => [
                        'no' => 'No', // @translate
                        'sw' => 'Starts with', // @translate
                        'in' => 'Contains', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'autocomplete',
                    'value' => 'no',
                ],
            ])
            ->add([
                'name' => 'value_languages',
                'type' => ArrayTextarea::class,
                'options' => [
                    'label' => 'Value languages for properties', // @translate
                    'as_key_value' => true,
                ],
                'attributes' => [
                    'id' => 'value_languages',
                ],
            ])
            ->add([
                'name' => 'default_language',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Default language', // @translate
                ],
                'attributes' => [
                    'id' => 'default_language',
                ],
            ])
            ->add([
                'name' => 'no_language',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'No language', // @translate
                ],
                'attributes' => [
                    'id' => 'no_language',
                ],
            ])
            ->add([
                'name' => 'autofillers',
                'type' => Element\Select::class,
                'options' => [
                    'label' => 'Autofillers', // @translate
                    'value_options' => $autofillers,
                    'empty_option' => count($autofillers)
                        ? ''
                        : $services->get('MvcTranslator')->translate('No configured autofiller.'), // @translate
                ],
                'attributes' => [
                    'id' => 'autofillers',
                    'multiple' => true,
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select autofillers…', // @translate
                ],
            ]);
    }

    public function addResourceTemplateFormFilters(Event $event): void
    {
        $event->getParam('inputFilter')
            ->get('o:data')
            ->add([
                'name' => 'autofillers',
                'required' => false,
            ]);
    }

    public function addResourceTemplatePropertyFieldsetElements(Event $event): void
    {
        /** @var \Omeka\Form\ResourceTemplatePropertyFieldset $fieldset */
        $fieldset = $event->getTarget();
        $fieldset
            ->add([
                'name' => 'default_value',
                'type' => Element\Textarea::class,
                'options' => [
                    'label' => 'Default value', // @translate
                ],
                'attributes' => [
                    // 'id' => 'default_value',
                    'class' => 'setting',
                    'data-setting-key' => 'default_value',
                ],
            ])
            ->add([
                'name' => 'locked_value',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Locked value once saved', // @translate
                ],
                'attributes' => [
                    // 'id' => 'locked_value',
                    'class' => 'setting',
                    'data-setting-key' => 'locked_value',
                ],
            ])
            ->add([
                'name' => 'autocomplete',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Autocomplete with existing values', // @translate
                    'value_options' => [
                        '' => 'Use template setting', // @translate
                        'no' => 'No', // @translate
                        'sw' => 'Starts with', // @translate
                        'in' => 'Contains', // @translate
                    ],
                ],
                'attributes' => [
                    // 'id' => 'autocomplete',
                    'class' => 'setting',
                    'data-setting-key' => 'autocomplete',
                    'value' => '',
                ],
            ])
            ->add([
                'name' => 'value_languages',
                'type' => ArrayTextarea::class,
                'options' => [
                    'label' => 'Suggested languages', // @translate
                    'as_key_value' => true,
                ],
                'attributes' => [
                    // 'id' => 'value_languages',
                    'class' => 'setting',
                    'data-setting-key' => 'value_languages',
                ],
            ])
            ->add([
                'name' => 'default_language',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Default language', // @translate
                ],
                'attributes' => [
                    // 'id' => 'default_language',
                    'class' => 'setting',
                    'data-setting-key' => 'default_language',
                ],
            ])
            ->add([
                'name' => 'use_language',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Use language', // @translate
                    'value_options' => [
                        '' => 'Use template setting', // @translate
                        'yes' => 'Yes', // @translate
                        'no' => 'No', // @translate
                    ],
                ],
                'attributes' => [
                    // 'id' => 'use_language',
                    'class' => 'setting',
                    'data-setting-key' => 'use_language',
                    'value' => 'template',
                ],
            ]);
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

        $fieldNameToProperty = $this->getServiceLocator()->get('ControllerPluginManager')->get('fieldNameToProperty');

        $result = [];
        $lines = $this->stringToList($string);
        $matches = [];
        $autofillerKey = null;
        foreach ($lines as $line) {
            // Start a new autofiller.
            $first = mb_substr($line, 0, 1);
            if ($first === '[') {
                preg_match('~^\[\s*(?<service>[a-zA-Z][a-zA-Z0-9]*)\s*(?:\:\s*(?<sub>[a-zA-Z][a-zA-Z0-9:]*))?\s*(?:#\s*(?<variant>[^\]]+))?\s*\]\s*(?:=?\s*(?<label>.*))$~', $line, $matches);
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
                $from = trim(mb_substr($line, 0, $pos));
                $to = trim(mb_substr($line, $pos + 1));
                if (!$from || !$to) {
                    continue;
                }
                $to = $fieldNameToProperty($to);
                if (!$to) {
                    continue;
                }
                $result[$autofillerKey]['mapping'][] = [
                    'from' => $from,
                    'to' => array_filter($to, function ($v) {
                        return !is_null($v);
                    }),
                ];
            }
        }
        return $result;
    }
}

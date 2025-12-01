<?php declare(strict_types=1);

namespace AdvancedResourceTemplate;

use Common\Stdlib\PsrMessage;
use Omeka\Module\Exception\ModuleCannotInstallException;

/**
 * @var Module $this
 * @var \Laminas\ServiceManager\ServiceLocatorInterface $services
 * @var string $newVersion
 * @var string $oldVersion
 *
 * @var \Omeka\Api\Manager $api
 * @var \Omeka\View\Helper\Url $url
 * @var \Laminas\Log\Logger $logger
 * @var \Omeka\Settings\Settings $settings
 * @var \Laminas\I18n\View\Helper\Translate $translate
 * @var \Doctrine\DBAL\Connection $connection
 * @var \Laminas\Mvc\I18n\Translator $translator
 * @var \Doctrine\ORM\EntityManager $entityManager
 * @var \Omeka\Settings\SiteSettings $siteSettings
 * @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger
 */
$plugins = $services->get('ControllerPluginManager');
$url = $plugins->get('url');
$api = $plugins->get('api');
$logger = $services->get('Omeka\Logger');
$settings = $services->get('Omeka\Settings');
$translate = $plugins->get('translate');
$translator = $services->get('MvcTranslator');
$connection = $services->get('Omeka\Connection');
$messenger = $plugins->get('messenger');
$siteSettings = $services->get('Omeka\Settings\Site');
$entityManager = $services->get('Omeka\EntityManager');

$localConfig = require dirname(__DIR__, 2) . '/config/module.config.php';

if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.74')) {
    $message = new \Omeka\Stdlib\Message(
        'The module %1$s should be upgraded to version %2$s or later.', // @translate
        'Common', '3.4.74'
    );
    throw new ModuleCannotInstallException((string) $message);
}

if ($this->isModuleActive('DynamicItemSets')
    && !$this->isModuleVersionAtLeast('DynamicItemSets', '3.4.5')
) {
    $message = new PsrMessage(
        $translate('Some features require the module {module} to be upgraded to version {version} or later.'), // @translate
        ['module' => 'Dynamic Item Sets', 'version' => '3.4.5']
    );
    throw new ModuleCannotInstallException((string) $message);
}

if ($this->isModuleActive('AdvancedSearch')
    && !$this->isModuleVersionAtLeast('AdvancedSearch', '3.4.53')
) {
    $message = new \Common\Stdlib\PsrMessage(
        $translate('Some features require the module {module} to be upgraded to version {version} or later.'), // @translate
        ['module' => 'Advanced Search', 'version' => '3.4.53']
    );
    $messenger = $services->get('ControllerPluginManager')->get('messenger');
    $messenger->addWarning($message);
}

if (version_compare((string) $oldVersion, '3.3.3.3', '<')) {
    $this->execSqlFromFile($this->modulePath() . '/data/install/schema.sql');
}

if (version_compare((string) $oldVersion, '3.3.4', '<')) {
    $sql = <<<'SQL'
        ALTER TABLE `resource_template_property_data`
        DROP INDEX UNIQ_B133BBAA2A6B767B;
        SQL;
    try {
        $connection->executeStatement($sql);
    } catch (\Exception $e) {
    }
    $sql = <<<'SQL'
        ALTER TABLE `resource_template_property_data`
        ADD INDEX IDX_B133BBAA2A6B767B (`resource_template_property_id`);
        SQL;
    try {
        $connection->executeStatement($sql);
    } catch (\Exception $e) {
    }
}

if (version_compare((string) $oldVersion, '3.3.4.3', '<')) {
    // @link https://www.doctrine-project.org/projects/doctrine-dbal/en/2.6/reference/types.html#array-types
    $sql = <<<'SQL'
        ALTER TABLE `resource_template_data`
        CHANGE `data` `data` LONGTEXT NOT NULL COMMENT '(DC2Type:json)';
        SQL;
    $connection->executeStatement($sql);
    $sql = <<<'SQL'
        ALTER TABLE `resource_template_property_data`
        CHANGE `data` `data` LONGTEXT NOT NULL COMMENT '(DC2Type:json)';
        SQL;
    $connection->executeStatement($sql);
}

if (version_compare((string) $oldVersion, '3.3.4.13', '<')) {
    // Add the term name to the list of suggested classes.
    $qb = $connection->createQueryBuilder();
    $qb
        ->select('id', 'data')
        ->from('resource_template_data', 'resource_template_data')
        ->orderBy('resource_template_data.id', 'asc')
        ->where('resource_template_data.data LIKE "%suggested_resource_class_ids%"')
    ;
    $templateDatas = $connection->executeQuery($qb)->fetchAllKeyValue();
    foreach ($templateDatas as $id => $templateData) {
        $templateData = json_decode($templateData, true);
        if (empty($templateData['suggested_resource_class_ids'])) {
            continue;
        }
        $result = [];
        foreach ($api->search('resource_classes', ['id' => array_values($templateData['suggested_resource_class_ids'])], ['initialize' => false])->getContent() as $class) {
            $result[$class->term()] = $class->id();
        }
        $templateData['suggested_resource_class_ids'] = $result;
        $quotedTemplateData = $connection->quote(json_encode($templateData));
        $sql = <<<SQL
            UPDATE `resource_template_data`
            SET
                `data` = $quotedTemplateData
            WHERE `id` = $id;
            SQL;
        $connection->executeStatement($sql);
    }

    $message = new PsrMessage(
        'New settings were added to the resource templates.' // @translate
    );
    $messenger->addSuccess($message);
    $message = new PsrMessage(
        'Values are now validated against settings in all cases, included background or direct api process.' // @translate
    );
    $messenger->addWarning($message);
}

if (version_compare((string) $oldVersion, '3.3.4.14', '<')) {
    // Use "yes" for all simple parameters.
    $qb = $connection->createQueryBuilder();
    $qb
        ->select('id', 'data')
        ->from('resource_template_data', 'resource_template_data')
    ;
    $templateDatas = $connection->executeQuery($qb)->fetchAllKeyValue();
    foreach ($templateDatas as $id => $templateData) {
        $templateData = json_decode($templateData, true);
        foreach ([
            'require_resource_class',
            'closed_class_list',
            'closed_property_list',
            'quick_new_resource',
            'no_language',
            'value_suggest_keep_original_label',
            'value_suggest_require_uri',
        ] as $key) {
            if (array_key_exists($key, $templateData)) {
                if (in_array($templateData[$key], [true, 1, '1', 'true', 'yes', 'on'], true)) {
                    $templateData[$key] = 'yes';
                } else {
                    unset($templateData[$key]);
                }
            }
        }
        $quotedTemplateData = $connection->quote(json_encode($templateData));
        $sql = <<<SQL
            UPDATE `resource_template_data`
            SET
                `data` = $quotedTemplateData
            WHERE `id` = $id;
            SQL;
        $connection->executeStatement($sql);
    }

    $qb = $connection->createQueryBuilder();
    $qb
        ->select('id', 'data')
        ->from('resource_template_property_data', 'resource_template_property_data')
    ;
    $templatePropertyDatas = $connection->executeQuery($qb)->fetchAllKeyValue();
    foreach ($templatePropertyDatas as $id => $templatePropertyData) {
        $templatePropertyData = json_decode($templatePropertyData, true);
        foreach ([
            'property_read_only',
            'locked_value',
        ] as $key) {
            if (array_key_exists($key, $templatePropertyData)) {
                if (in_array($templatePropertyData[$key], [true, 1, '1', 'true', 'yes', 'on'], true)) {
                    $templatePropertyData[$key] = 'yes';
                } else {
                    unset($templatePropertyData[$key]);
                }
            }
        }
        $quotedTemplatePropertyData = $connection->quote(json_encode($templatePropertyData));
        $sql = <<<SQL
            UPDATE `resource_template_property_data`
            SET
                `data` = $quotedTemplatePropertyData
            WHERE `id` = $id;
            SQL;
        $connection->executeStatement($sql);
    }

    $settings->set('advancedresourcetemplate_resource_form_elements',
        $localConfig['advancedresourcetemplate']['settings']['advancedresourcetemplate_resource_form_elements']);

    $message = new PsrMessage(
        'New settings were added to the template.' // @translate
    );
    $messenger->addSuccess($message);
    $message = new PsrMessage(
        'New settings were added to the {link}main settings{link_end} to simplify resource form.', // @translate
        [
            'link' => '<a href="' . $url->fromRoute('admin/default', ['controller' => 'setting', 'action' => 'browse']) . '#advanded-resource-template">',
            'link_end' => '</a>',
        ]
    );
    $message->setEscapeHtml(false);
    $messenger->addSuccess($message);
}

if (version_compare((string) $oldVersion, '3.3.4.15', '<')) {
    $message = new PsrMessage(
        'It’s now possible to group a long list of template properties.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare((string) $oldVersion, '3.4.4.16', '<')) {
    // Replace the option "default_language" by the new "o:default_language".
    $qb = $connection->createQueryBuilder();
    $qb
        ->select('*')
        ->from('resource_template_property_data', 'resource_template_property_data')
    ;
    $templatePropertyDatas = $connection->executeQuery($qb)->fetchAllAssociative();
    $sqlRtp = <<<SQL
        UPDATE `resource_template_property`
        SET
            `default_lang` = :default_lang
        WHERE `id` = :rtp_id;
        SQL;
    $sqlRtpd = <<<SQL
        UPDATE `resource_template_property_data`
        SET
            `data` = :data
        WHERE `id` = :id;
        SQL;
    foreach ($templatePropertyDatas as $templatePropertyData) {
        $rtpData = json_decode($templatePropertyData['data'], true);
        if (!empty($rtpData['default_language'])) {
            $connection->executeStatement($sqlRtp, [
                'default_lang' => $rtpData['default_language'],
                'rtp_id' => (int) $templatePropertyData['resource_template_property_id'],
            ]);
        }
        $rtpData['o:default_lang'] = empty($rtpData['default_language']) ? null : $rtpData['default_language'];
        unset($rtpData['default_language']);
        $connection->executeStatement($sqlRtpd, [
            'data' => json_encode($rtpData),
            'id' => (int) $templatePropertyData['id'],
        ]);
    }
}

if (version_compare((string) $oldVersion, '3.4.4.18', '<')) {
    $message = new PsrMessage(
        'It’s now possible to set a control input for literal values.' // @translate
    );
    $messenger->addSuccess($message);
    $message = new PsrMessage(
        'It’s now possible to set a custom vocab open, so the user can complete the authority list when filiing data for a resource.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare((string) $oldVersion, '3.4.20', '<')) {
    $message = new PsrMessage(
        'It’s now possible to set resource template for annotations on each property.' // @translate
    );
    $messenger->addSuccess($message);
    $message = new PsrMessage(
        'The format for automatic value and autofilling has changed slightly and upgrade is not automatic. You should check them if you use this feature. See {link}readme{link_end} for more info.', // @translate
        [
            'link' => '<a href="https://gitlab.com/Daniel-KM/Omeka-S-module-AdvancedResourceTemplate#automatic-value" _target="blank" rel="noopener">',
            'link_end' => '</a>',
        ]
    );
    $message->setEscapeHtml(false);
    $messenger->addWarning($message);
}

if (version_compare((string) $oldVersion, '3.4.21', '<')) {
    $message = new PsrMessage(
        'It’s now possible to order linked resources by another property than title (require Omeka S v4.1).' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare((string) $oldVersion, '3.4.22', '<')) {
    // Create a resource data for all resource templates.
    $sql = <<<SQL
        INSERT INTO resource_template_data (resource_template_id, data)
        SELECT resource_template.id, "{}"
        FROM resource_template
        LEFT JOIN resource_template_data ON resource_template_data.resource_template_id = resource_template.id
        WHERE resource_template_data.resource_template_id IS NULL
        ;
        SQL;
    $connection->executeStatement($sql);

    // Make all templates available to all resources by default.
    $qb = $connection->createQueryBuilder();
    $qb
        ->select(
            'resource_template_data.id',
            'resource_template_data.resource_template_id',
            'resource_template_data.data'
        )
        ->from('resource_template_data', 'resource_template_data')
    ;
    $templateDatas = $connection->executeQuery($qb)->fetchAllAssociativeIndexed();

    // Except templates used for Annotations (module Cartography).
    $annotationTemplates = $settings->get('cartography_template_describe') ?: [];
    $annotationTemplates = array_merge($annotationTemplates, $settings->get('cartography_template_locate') ?: []);
    // It is not possible to search by class before Omeka S v4.1.
    try {
        $property = 'oa:Annotation';
        $vocabularyId = $api->read('vocabularies', ['prefix' => strtok($property, ':')])->getContent()->id();
        $classAnnotation = $api->read('resource_classes', ['vocabulary' => $vocabularyId, 'localName' => strtok(':')])->getContent();
    } catch (\Exception $e) {
        $classAnnotation = null;
    }
    if ($classAnnotation) {
        $qb = $connection->createQueryBuilder();
        $qb
            ->select('id')
            ->from('resource_template', 'resource_template')
            ->where($qb->expr()->eq('resource_class_id', $classAnnotation->id()))
        ;
        $annotationTemplatesMore = $connection->executeQuery($qb)->fetchFirstColumn() ?: [];
        $annotationTemplates = array_merge($annotationTemplates, $annotationTemplatesMore);
    }
    $annotationTemplates = array_unique(array_map('intval', $annotationTemplates));

    // Except template for Thesaurus.
    $thesaurusTemplates = [];
    try {
        $thesaurusTemplateScheme = $api->read('resource_templates', ['label' => 'Thesaurus Scheme'])->getContent();
    } catch (\Exception $e) {
        $thesaurusTemplateScheme = null;
    }
    try {
        $thesaurusTemplateConcept = $api->read('resource_templates', ['label' => 'Thesaurus Concept'])->getContent();
    } catch (\Exception $e) {
        $thesaurusTemplateConcept = null;
    }
    if ($thesaurusTemplateScheme) {
        $thesaurusTemplates[] = $thesaurusTemplateScheme->id();
    }
    if ($thesaurusTemplateConcept) {
        $thesaurusTemplates[] = $thesaurusTemplateConcept->id();
    }
    $thesaurusTemplateSchemeId = $settings->get('thesaurus_skos_scheme_template_id');
    if ($thesaurusTemplateSchemeId) {
        try {
            $thesaurusTemplateScheme = $api->read('resource_templates', ['id' => $thesaurusTemplateSchemeId])->getContent();
        } catch (\Exception $e) {
            $thesaurusTemplateScheme = null;
        }
        if ($thesaurusTemplateScheme) {
            $thesaurusTemplates[] = $thesaurusTemplateScheme->id();
        }
    }
    $thesaurusTemplateConceptId = $settings->get('thesaurus_skos_concept_template_id');
    if ($thesaurusTemplateConceptId) {
        try {
            $thesaurusTemplateConcept = $api->read('resource_templates', ['id' => $thesaurusTemplateConceptId])->getContent();
        } catch (\Exception $e) {
            $thesaurusTemplateConcept = null;
        }
        if ($thesaurusTemplateConcept) {
            $thesaurusTemplates[] = $thesaurusTemplateConcept->id();
        }
    }
    $thesaurusTemplates = array_unique(array_map('intval', $thesaurusTemplates));

    foreach ($templateDatas as $id => $templateRow) {
        $templateData = json_decode($templateRow['data'], true) ?: [];
        if (isset($templateData['use_for_resources']) && $templateData['use_for_resources'] === ['value_annotations']) {
            $templateData['use_for_resources'] = ['value_annotations'];
        } elseif (in_array($templateRow['resource_template_id'], $annotationTemplates)) {
            $templateData['use_for_resources'] = ['annotations'];
        } elseif (in_array($templateRow['resource_template_id'], $thesaurusTemplates)) {
            $templateData['use_for_resources'] = ['items'];
        } else {
            $templateData['use_for_resources'] = (int) $templateRow['resource_template_id'] === 1
                ? ['items', 'media', 'item_sets']
                : ['items'];
        }
        $quotedTemplateData = $connection->quote(json_encode($templateData));
        $sql = <<<SQL
            UPDATE `resource_template_data`
            SET
                `data` = $quotedTemplateData
            WHERE `id` = $id;
            SQL;
        $connection->executeStatement($sql);
    }

    $this->storeResourceTemplateSettings();

    $message = new PsrMessage(
        'It’s now possible to limit templates available by resource, for example the template "Incunable" for items only and the template "Folio" for medias only.' // @translate
    );
    $messenger->addSuccess($message);

    $message = new PsrMessage(
        'It’s now possible to specify templates by property for value annotations.' // @translate
    );
    $messenger->addSuccess($message);

    $message = new PsrMessage(
        'All existing templates are made available by items only. Check your templates if you need.' // @translate
    );
    $messenger->addWarning($message);

    $message = new PsrMessage(
        'If you use specific templates, you may have to check this new parameter.' // @translate
    );
    $messenger->addWarning($message);
}

if (version_compare((string) $oldVersion, '3.4.23', '<')) {
    $message = new PsrMessage(
        'It’s now possible to specify templates for annotations of module Annotate.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare((string) $oldVersion, '3.4.25', '<')) {
    $settings->set('advancedresourcetemplate_skip_private_values', false);
    $message = new PsrMessage(
        'A new main setting was added to hide private values in public sites.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare((string) $oldVersion, '3.4.26', '<')) {
    // Update tables with new index names.
    $sql = <<<'SQL'
        ALTER TABLE `resource_template_data`
            DROP INDEX UNIQ_31D1FFC816131EA;
        SQL;
    try {
        $connection->executeStatement($sql);
    } catch (\Exception $e) {
    }
    $sql = <<<'SQL'
        ALTER TABLE `resource_template_property_data`
            DROP INDEX IDX_B133BBAA16131EA,
            DROP INDEX IDX_B133BBAA2A6B767B;
        SQL;
    try {
        $connection->executeStatement($sql);
    } catch (\Exception $e) {
    }
    $sql = <<<'SQL'
        ALTER TABLE `resource_template_data`
            ADD UNIQUE INDEX uniq_resource_template_id (`resource_template_id`);
        SQL;
    try {
        $connection->executeStatement($sql);
    } catch (\Exception $e) {
    }
    $sql = <<<'SQL'
        ALTER TABLE `resource_template_property_data`
            ADD INDEX idx_resource_template_id (`resource_template_id`),
            ADD INDEX idx_resource_template_property_id (`resource_template_property_id`);
        SQL;
    try {
        $connection->executeStatement($sql);
    } catch (\Exception $e) {
    }

    // Add the resource template and resource class to value annotations.
    // TODO Use a single query instead of four requests (or use a temp view).

    // Get the default template id for all templates.
    $sql = <<<'SQL'
        SELECT
            `resource_template_id` AS rtid,
            REPLACE(SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(
                `data`, '"value_annotations_template":', -1
            ), ',', 1), '}', 1), '"', '') AS vartid
        FROM `resource_template_data`
        WHERE `data` LIKE '%"value#_annotations#_template":%' ESCAPE "#"
            AND `data` NOT LIKE '%"value#_annotations#_template":""%' ESCAPE "#"
            AND `data` NOT LIKE '%"value#_annotations#_template":"none"%' ESCAPE "#"
        ;
        SQL;
    $rtVaTemplates = $connection->executeQuery($sql)->fetchAllKeyValue();

    // Get the specific template id for all property templates.
    $sql = <<<'SQL'
        SELECT
            CONCAT(`resource_template_property`.`resource_template_id`, "-", `property_id`),
            REPLACE(SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(
                `data`, '"value_annotations_template":', -1
            ), ',', 1), '}', 1), '"', '') AS vartid
        FROM `resource_template_property_data`
        JOIN `resource_template_property` ON `resource_template_property`.`id` = `resource_template_property_data`.`resource_template_property_id`
        WHERE `data` LIKE '%"value#_annotations#_template":%' ESCAPE "#"
        ;
        SQL;
    $rtpVaTemplates = $connection->executeQuery($sql)->fetchAllKeyValue();

    // Get the main class associated with the templates.
    $sql = <<<'SQL'
        SELECT `id`, `resource_class_id`
        FROM `resource_template`
        WHERE `resource_class_id` IS NOT NULL
        ;
        SQL;
    $templateClasses = $connection->executeQuery($sql)->fetchAllKeyValue();

    // Set default value annotation template when there is no specific property
    // value annotation template.
    foreach ($rtpVaTemplates as $rtProp => &$rtpVaTemplate) {
        $rtpVaTemplate = $rtpVaTemplate ?: ($rtVaTemplates[strtok($rtProp, '-')] ?? null);
    }
    unset($rtpVaTemplate);

    $rtpVaTemplates = array_filter($rtpVaTemplates);

    if (count($rtpVaTemplates)) {
        $rtVaTemplatesString = '';
        $rtVaClassesString = '';
        $rtVaTemplatesCase = '';
        $rtVaClassesCase = '';
        foreach ($rtpVaTemplates as $rtProp => $rtpVaTemplate) {
            $rtVaTemplatesCase .= is_numeric($rtpVaTemplate)
                ? sprintf("        WHEN '%s' THEN %s\n", $rtProp, $rtpVaTemplate)
                : '';
            $rtVaClassesCase .= isset($templateClasses[$rtpVaTemplate])
                ? sprintf("        WHEN '%s' THEN %s\n", $rtProp, $templateClasses[$rtpVaTemplate])
                : '';
        }
        if (trim($rtVaTemplatesCase)) {
            $rtVaTemplatesString = '    CASE CONCAT(`resource_main`.`resource_template_id`, "-", `value`.`property_id`)' . "\n        "
                . $rtVaTemplatesCase
                . "        ELSE NULL\n    END";
        }
        if (trim($rtVaClassesCase)) {
            $rtVaClassesString = '    CASE CONCAT(`resource_main`.`resource_template_id`, "-", `value`.`property_id`)' . "\n        "
                . $rtVaClassesCase
                . "        ELSE NULL\n    END";
        }
    }
    if (empty($rtVaTemplatesString)) {
        $rtVaTemplatesString = 'NULL';
    }
    if (empty($rtVaClassesString)) {
        $rtVaClassesString = 'NULL';
    }

    // Do the update.
    $sql = <<<SQL
        UPDATE `resource`
        INNER JOIN `value` ON `value`.`value_annotation_id` = `resource`.`id`
        LEFT JOIN `resource` AS `resource_main` ON `resource_main`.`id` = `value`.`resource_id`
        SET
            `resource`.`resource_class_id` = $rtVaClassesString,
            `resource`.`resource_template_id` = $rtVaTemplatesString
        WHERE `value`.`value_annotation_id` IS NOT NULL
        ;
        SQL;
    $connection->executeStatement($sql);

    // Update new names of geometric data types that is not managed in the module.
    $sql = <<<SQL
        UPDATE `resource_template_property`
        SET
            `resource_template_property`.`data_type` = REPLACE(REPLACE(`resource_template_property`.`data_type`,
                "geometry:geometry", "geometry"),
                "geometry:geography", "geography")
        WHERE `resource_template_property`.`data_type` LIKE "%geometry%"
        ;
        UPDATE `resource_template_property_data`
        SET
            `resource_template_property_data`.`data` = REPLACE(REPLACE(`resource_template_property_data`.`data`,
                "geometry:geometry", "geometry"),
                "geometry:geography", "geography")
        WHERE `resource_template_property_data`.`data` LIKE "%geometry%"
        ;
        UPDATE `resource_template_data`
        SET
            `resource_template_data`.`data` = REPLACE(REPLACE(`resource_template_data`.`data`,
                "geometry:geometry", "geometry"),
                "geometry:geography", "geography")
        WHERE `resource_template_data`.`data` LIKE "%geometry%"
        ;
        SQL;
    $connection->executeStatement($sql);

    $message = new PsrMessage(
        'Value annotations can now have a resource class and an alternative label or comment.' // @translate
    );
    $messenger->addSuccess($message);

    $hasEasyAdmin = $this->isModuleActive('EasyAdmin');
    $message = new PsrMessage(
        'A job is added in tasks of the module {link}Easy Admin{link_end} to fill the annotation templates and classes when needed.', // @translate
        [
            'link' => sprintf('<a href="%s">', $hasEasyAdmin ? $url->fromRoute('admin/default', ['controller' => 'easy-admin', 'action' => 'check-and-fix'], ['fragment' => 'resource_values']) : 'https://omeka.org/s/modules/EasyAdmin'),
            'link_end' => '</a>',
        ]
    );
    $message->setEscapeHtml(false);
    $messenger->addSuccess($message);
}

if (version_compare((string) $oldVersion, '3.4.27', '<')) {
    // $this->updateItemSetsQueries();
    $queries = $settings->get('advancedresourcetemplate_item_set_queries') ?: [];
    if ($queries) {
        // Use connection because the current user may not have access to all
        // item sets. Check all item sets one time.
        $itemSetIds = $connection
            ->executeQuery(
                'SELECT `id`, `id` FROM `item_set` WHERE `id` IN (:ids)',
                ['ids' => array_keys($queries)],
                ['ids' => \Doctrine\DBAL\Connection::PARAM_INT_ARRAY]
            )
            ->fetchAllKeyValue();
        $queries = array_intersect_key($queries, $itemSetIds);
        $settings->set('advancedresourcetemplate_item_set_queries', $queries);
    }
}

if (version_compare((string) $oldVersion, '3.4.29', '<')) {
    $message = new PsrMessage(
        'The feature to display the property select with all alternative labels of templates was moved to a new module {link}Alternative Label Select{link_end}. You should install it if you need it.', // @translate
        [
            'link' => '<a href="https://gitlab.com/Daniel-KM/Omeka-S-module-AlternativeLabelSelect" _target="blank" rel="noopener">',
            'link_end' => '</a>',
        ]
    );
    $message->setEscapeHtml(false);
    $messenger->addWarning($message);
}

if (version_compare((string) $oldVersion, '3.4.31', '<')) {
    $settings->set('advancedresourcetemplate_properties_as_search_blacklist',
        $localConfig['advancedresourcetemplate']['settings']['advancedresourcetemplate_properties_as_search_blacklist']);
    $settings->delete('advancedresourcetemplate_properties_as_search');

    $message = new PsrMessage(
        'A new option allows to display property values as search links, or to add a search icon to values, compatible with module {link}Advanced search{link_end}.', // @translate
        [
            'link' => '<a href="https://gitlab.com/Daniel-KM/Omeka-S-module-AdvancedSearch" _target="blank" rel="noopener">',
            'link_end' => '</a>',
        ]
    );
    $message->setEscapeHtml(false);
    $messenger->addWarning($message);
}

if (version_compare((string) $oldVersion, '3.4.32', '<')) {
    $blockMetadataFields = $localConfig['advancedresourcetemplate']['site_settings']['advancedresourcetemplate_block_metadata_fields'] ?? [];
    $blockMetadataComponents = $localConfig['advancedresourcetemplate']['site_settings']['advancedresourcetemplate_block_metadata_components'] ?? [];
    $siteIds = $api->search('sites', [], ['returnScalar' => 'id'])->getContent();
    foreach ($siteIds as $siteId) {
        $siteSettings->setTargetId($siteId);
        $siteSettings->set('advancedresourcetemplate_block_metadata_fields', $blockMetadataFields);
        $siteSettings->set('advancedresourcetemplate_block_metadata_components', $blockMetadataComponents);
    }

    $message = new PsrMessage(
        'A new resource block was added to display selected metadata, for example for a short record.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare((string) $oldVersion, '3.4.37', '<')) {
    $message = new PsrMessage(
        'A new check can be done on the number of attached medias.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare((string) $oldVersion, '3.4.38', '<')) {
    $message = new PsrMessage(
        'The feature to create dynamic item sets was moved to a new module {link}Dynamic Item Sets{link_end}. You should install it if you need it.', // @translate
        [
            'link' => '<a href="https://gitlab.com/Daniel-KM/Omeka-S-module-DynamicItemSets" _target="blank" rel="noopener">',
            'link_end' => '</a>',
        ]
    );
    $message->setEscapeHtml(false);
    $messenger->addWarning($message);

    $itemSetQueries = $settings->get('advancedresourcetemplate_item_set_queries', []);
    if ($itemSetQueries) {
        // For compatibility with new name in module Dynamic Item Sets.
        if ($settings->get('dynamicitemsets_item_sets_queries_dynamic') === null) {
            $settings->set('dynamicitemsets_item_sets_queries_dynamic', $itemSetQueries);
        }
        if ($settings->get('dynamicitemsets_item_set_queries') === null) {
            $settings->set('dynamicitemsets_item_set_queries', $itemSetQueries);
        }
        $list = [];
        $baseUrlItemSet = rtrim($url->fromRoute('admin/id', ['controller' => 'item-set', 'id' => '00']), '0');
        foreach (array_keys($itemSetQueries) as $itemSetId) {
            $list[] = sprintf('#<a href="%s">%d</a>', $baseUrlItemSet . $itemSetId, $itemSetId);
        }
        $message = new PsrMessage(
            'Currently, the feature is used by {count} item sets: {item_sets}. Upgrade is automatic.', // @translate
            [
                'count' => count($itemSetQueries),
                'item_sets' => implode(', ', $list),
            ]
        );
        $message->setEscapeHtml(false);
        $messenger->addWarning($message);
    } else {
        $message = new PsrMessage(
            'Currently, the feature is not used by any item set.' // @translate
        );
        $messenger->addWarning($message);
    }
}

if (version_compare((string) $oldVersion, '3.4.39', '<')) {
    $message = new PsrMessage(
        'It is now possible to set item sets via the template.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare((string) $oldVersion, '3.4.40', '<')) {
    $message = new PsrMessage(
        'It is now possible to display fake values in record, like "Unknown author".' // @translate
    );
    $messenger->addSuccess($message);

    $message = new PsrMessage(
        'It is now possible to store the date when the the resource is issued for the first time.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare((string) $oldVersion, '3.4.47', '<')) {
    $siteIds = $api->search('sites', [], ['returnScalar' => 'id'])->getContent();
    foreach ($siteIds as $siteId) {
        $siteSettings->setTargetId($siteId);
        $siteSettings->set('advancedresourcetemplate_skip_private_values', '');
    }

    $message = new PsrMessage(
        'A new site setting allows to hide private values in public interface even for admin users.' // @translate
    );
    $messenger->addSuccess($message);

    $message = new PsrMessage(
        'A new template setting allows to set fallbacks properties for title.' // @translate
    );
    $messenger->addSuccess($message);
}

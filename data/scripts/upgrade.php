<?php declare(strict_types=1);

namespace AdvancedResourceTemplate;

use Omeka\Mvc\Controller\Plugin\Messenger;
use Omeka\Stdlib\Message;

/**
 * @var Module $this
 * @var \Laminas\ServiceManager\ServiceLocatorInterface $serviceLocator
 * @var string $newVersion
 * @var string $oldVersion
 *
 * @var \Doctrine\DBAL\Connection $connection
 * @var \Doctrine\ORM\EntityManager $entityManager
 * @var \Omeka\Api\Manager $api
 */
$services = $serviceLocator;
// $settings = $services->get('Omeka\Settings');
// $config = require dirname(__DIR__, 2) . '/config/module.config.php';
$connection = $services->get('Omeka\Connection');
// $entityManager = $services->get('Omeka\EntityManager');
$plugins = $services->get('ControllerPluginManager');
$api = $plugins->get('api');

if (version_compare((string) $oldVersion, '3.3.3.3', '<')) {
    $this->execSqlFromFile($this->modulePath() . '/data/install/schema.sql');
}

if (version_compare((string) $oldVersion, '3.3.4', '<')) {
    $sql = <<<'SQL'
ALTER TABLE `resource_template_property_data`
DROP INDEX UNIQ_B133BBAA2A6B767B,
ADD INDEX IDX_B133BBAA2A6B767B (`resource_template_property_id`);
SQL;
    $connection->executeStatement($sql);
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

    $messenger = new Messenger();
    $message = new Message(
        'New settings were added to the resource templates.' // @translate
    );
    $messenger->addSuccess($message);
    $message = new Message(
        'Values are now validated against settings in all cases, included background or direct api process.' // @translate
    );
    $messenger->addWarning($message);
}

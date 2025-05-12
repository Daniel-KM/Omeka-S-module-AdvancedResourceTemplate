<?php declare(strict_types=1);

namespace AdvancedResourceTemplate\Autofiller;

use Laminas\ServiceManager\ServiceLocatorInterface;

abstract class AbstractAutofiller implements AutofillerInterface
{
    /**
     * @var string
     */
    protected $label;

    /**
     * @var array
     */
    protected $mapping;

    /**
     * @var array
     */
    protected $options;

    /**
     * @var \Doctrine\DBAL\Connection
     */
    protected $connection;

    /**
     * @var \Laminas\Http\Client
     */
    protected $httpClient;

    /**
     * @var \AdvancedResourceTemplate\Mvc\Controller\Plugin\ArtMapper
     */
    protected $mapper;

    /**
     * @var \Laminas\ServiceManager\ServiceLocatorInterface
     */
    protected $services;

    public function __construct(ServiceLocatorInterface $services, array $options = [])
    {
        $pluginManager = $services->get('ControllerPluginManager');
        $this->services = $services;
        $this->options = $options;
        $this->connection = $services->get('Omeka\Connection');
        $this->httpClient = $services->get('Omeka\HttpClient');
        $this->mapper = $pluginManager->get('artMapper');
    }

    public function getLabel(): string
    {
        if (!empty($this->options['label'])) {
            return $this->options['label'];
        }
        if (empty($this->options['sub'])) {
            return $this->label;
        }
        return sprintf(
            '%1$s: %2$s', // @translate
            $this->label,
            is_array($this->options['sub']) && $this->options['sub']['label']
                ? $this->options['sub']['label']
                : $this->options['sub']
        );
    }

    public function setMapping(array $mapping)
    {
        $this->mapping = $mapping;
        return $this;
    }

    abstract public function getResults($query, $lang = null): ?array;

    protected function finalizeSuggestions(array $uriLabels, array $uriMetadata, ?string $dataType = null): array
    {
        if (!$uriLabels) {
            return [];
        }

        // Keep sort by most used uris first.
        $hasUris = !is_numeric(key($uriLabels));
        $uris = array_fill_keys(array_keys($uriLabels), 0);
        if ($hasUris) {
            $totals = $this->totalsByUri(array_keys($uris), $dataType);
            $uris = $totals + $uris;
        }

        $suggestions = [];
        foreach ($uris as $uri => $count) {
            $suggestions[] = [
                'value' => $hasUris ? sprintf('%s (%s)', $uriLabels[$uri], $count) : $uriLabels[$uri],
                'data' => $uriMetadata[$uri],
                'info' => [
                    'uri' => $uri,
                    'label' => $uriLabels[$uri],
                    'count' => $count,
                ],
            ];
        }

        return $suggestions;
    }

    protected function totalsByUri(array $uris, ?string $dataType = null): array
    {
        if (!count($uris)) {
            return [];
        }

        // TODO Use doctrine query builder.
        $bind = [
            'uris' => array_values($uris),
        ];
        $types = [
            'uris' => \Doctrine\DBAL\Connection::PARAM_STR_ARRAY,
        ];
        if ($dataType) {
            $andWhere = "\n    AND `value`.`type` = :data_type\n";
            $bind['data_type'] = $dataType;
            $types['data_type'] = \Doctrine\DBAL\ParameterType::STRING;
        } else {
            $andWhere = '';
        }

        // Get all the totals for the data type one time.
        $sql = <<<SQL
            SELECT `value`.`uri`, COUNT(`value`.`uri`)
            FROM `value`
            WHERE `value`.`uri` IN (:uris)$andWhere
            GROUP BY `value`.`uri`
            ORDER BY COUNT(`value`.`uri`) DESC
            ;
            SQL;
        $totals = $this->connection->executeQuery($sql, $bind, $types)->fetchAllKeyValue();
        return $totals ? array_map('intval', $totals) : [];
    }
}

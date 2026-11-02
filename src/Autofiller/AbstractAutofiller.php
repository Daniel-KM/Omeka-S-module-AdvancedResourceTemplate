<?php declare(strict_types=1);

namespace AdvancedResourceTemplate\Autofiller;

use Laminas\Http\Client as HttpClient;
use Laminas\ServiceManager\ServiceLocatorInterface;

/**
 * Query a remote service and convert each result into values via module Mapper.
 */
abstract class AbstractAutofiller
{
    /**
     * @var string
     */
    protected $label;

    /**
     * Options: key, service, sub, label, mapping (reference or content of a
     * mapping of module Mapper).
     *
     * @var array
     */
    protected $options;

    /**
     * @var \Doctrine\DBAL\Connection
     */
    protected $connection;

    /**
     * @var HttpClient
     */
    protected $httpClient;

    /**
     * @var \Mapper\Stdlib\Mapper
     */
    protected $mapper;

    /**
     * @var ServiceLocatorInterface
     */
    protected $services;

    public function __construct(ServiceLocatorInterface $services, array $options = [])
    {
        $this->services = $services;
        $this->options = $options;
        $this->connection = $services->get('Omeka\Connection');
        $this->httpClient = $services->get('Omeka\HttpClient');
        $this->mapper = $services->get('Mapper\Mapper');
        $this->mapper->setMapping('autofiller:' . ($options['key'] ?? static::class), $this->mappingReference());
    }

    public function setHttpClient(HttpClient $httpClient): self
    {
        $this->httpClient = $httpClient;
        return $this;
    }

    public function getLabel(): string
    {
        if (!empty($this->options['label'])) {
            return $this->options['label'];
        }
        $label = $this->mapper->getMapping()['info']['label'] ?? null;
        if ($label && empty($this->options['mapping_is_default'])) {
            return $label;
        }
        return empty($this->options['sub'])
            ? $this->label
            : sprintf('%1$s: %2$s', $this->label, $this->options['sub']);
    }

    /**
     * Get the list of suggestions for the query, or null if the service is
     * unavailable.
     */
    abstract public function getResults(string $query, ?string $lang = null): ?array;

    /**
     * The mapping is a reference of module Mapper ("mapping:5", "user:x.ini",
     * "module:x.xml"), else the default mapping of the service.
     */
    protected function mappingReference()
    {
        if (!empty($this->options['mapping'])) {
            return $this->options['mapping'];
        }
        $this->options['mapping_is_default'] = true;
        $filepath = dirname(__DIR__, 2) . '/data/autofillers/' . $this->options['service'] . '.ini';
        return file_exists($filepath) ? file_get_contents($filepath) : '';
    }

    /**
     * Prepare the suggestions for jQuery Autocomplete, the most used uris first.
     *
     * @param array $uriLabels Labels by uri or by index.
     * @param array $uriMetadata Values by uri or by index.
     */
    protected function finalizeSuggestions(array $uriLabels, array $uriMetadata, ?string $dataType = null): array
    {
        if (!$uriLabels) {
            return [];
        }

        $hasUris = !is_numeric(key($uriLabels));
        $uris = array_fill_keys(array_keys($uriLabels), 0);
        if ($hasUris) {
            $uris = $this->totalsByUri(array_keys($uris), $dataType) + $uris;
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

    /**
     * Count the uses of each uri, to sort the suggestions.
     */
    protected function totalsByUri(array $uris, ?string $dataType = null): array
    {
        if (!$uris) {
            return [];
        }
        $qb = $this->connection->createQueryBuilder();
        $qb
            ->select('value.uri', 'COUNT(value.uri)')
            ->from('value', 'value')
            ->where($qb->expr()->in('value.uri', ':uris'))
            ->groupBy('value.uri')
            ->orderBy('COUNT(value.uri)', 'DESC')
            ->setParameter('uris', array_values($uris), \Doctrine\DBAL\Connection::PARAM_STR_ARRAY);
        if ($dataType) {
            $qb
                ->andWhere($qb->expr()->eq('value.type', ':data_type'))
                ->setParameter('data_type', $dataType);
        }
        $totals = $this->connection
            ->executeQuery($qb->getSQL(), $qb->getParameters(), $qb->getParameterTypes())
            ->fetchAllKeyValue();
        return array_map('intval', $totals);
    }

    /**
     * Get a param of the section [params] of the mapping, like "query" or
     * "url".
     */
    protected function mappingParam(string $name): ?string
    {
        $value = $this->mapper->getMapperConfig()->getSectionSetting('params', $name);
        return is_scalar($value) && strlen((string) $value) ? (string) $value : null;
    }

    /**
     * Get the parsed query params of the param "query" of the mapping.
     */
    protected function queryParams(): array
    {
        $params = [];
        parse_str((string) $this->mappingParam('query'), $params);
        return $params;
    }
}

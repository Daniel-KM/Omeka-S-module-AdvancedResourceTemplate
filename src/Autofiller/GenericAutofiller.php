<?php declare(strict_types=1);

namespace AdvancedResourceTemplate\Autofiller;

use SimpleXMLElement;

/**
 * Query any json or xml service defined in the section [params] of the mapping.
 *
 * Params: "url" (with the placeholder "{query}" in the url or in the param
 * "query"), "query" (query string), "resources_root" (path to the list of
 * results) and "label" (path to the label of each result, else the first
 * value).
 */
class GenericAutofiller extends AbstractAutofiller
{
    protected $label = 'Generic'; // @translate

    public function getResults(string $query, ?string $lang = null): ?array
    {
        $format = $this->options['sub'] ?? null;
        $url = (string) $this->mappingParam('url');
        $queryString = (string) $this->mappingParam('query');
        if (!in_array($format, ['json', 'xml'])
            || !filter_var(strtr($url, ['{query}' => 'x']), FILTER_VALIDATE_URL)
            || (strpos($url . $queryString, '{query}') === false)
        ) {
            return null;
        }

        $params = [];
        parse_str(strtr($queryString, ['{query}' => $query]), $params);
        $response = $this->httpClient
            ->resetParameters()
            ->setUri(strtr($url, ['{query}' => rawurlencode($query)]))
            ->setParameterGet($params)
            ->send();
        if (!$response->isSuccess()) {
            return null;
        }

        $results = $format === 'json'
            ? $this->extractResultsJson($response->getBody())
            : $this->extractResultsXml($response->getBody());
        if (!$results) {
            return [];
        }

        $labelPath = $this->mappingParam('label');
        $querier = $this->mapper->getMapperConfig()->getSectionSetting('info', 'querier') ?: 'jsdot';
        $defaultLabel = $this->services->get('MvcTranslator')->translate('[Result]');

        $uriLabels = [];
        $uriData = [];
        foreach ($results as $result) {
            $metadata = $this->mapper->convert($result);
            if (!$metadata) {
                continue;
            }
            $label = $labelPath ? $this->mapper->extractValue($result, $labelPath, $querier) : null;
            $label = is_array($label) ? reset($label) : $label;
            if (!is_scalar($label) || !strlen((string) $label)) {
                $first = reset($metadata);
                $label = $first[0]['@value'] ?? $first[0]['@id'] ?? $defaultLabel;
            }
            $uriLabels[] = (string) $label;
            $uriData[] = $metadata;
        }

        return $this->finalizeSuggestions($uriLabels, $uriData);
    }

    protected function extractResultsJson(string $content): array
    {
        $results = json_decode($content, true);
        if (!is_array($results)) {
            return [];
        }
        $root = $this->mappingParam('resources_root');
        if ($root) {
            $results = $this->mapper->extractSubValue($results, $root, []);
        }
        return is_array($results) ? array_values(array_filter($results, 'is_array')) : [];
    }

    /**
     * @return SimpleXMLElement[]
     */
    protected function extractResultsXml(string $content): array
    {
        $xml = @simplexml_load_string($content);
        if (!$xml) {
            return [];
        }
        $root = $this->mappingParam('resources_root');
        if (!$root) {
            return [$xml];
        }
        $results = $xml->xpath($root);
        if (!$results) {
            return [];
        }
        // Each result is converted as a standalone document for relative paths.
        return array_filter(array_map(fn ($v) => @simplexml_load_string($v->asXML()) ?: null, $results));
    }
}

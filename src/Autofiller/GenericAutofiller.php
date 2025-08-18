<?php declare(strict_types=1);

namespace AdvancedResourceTemplate\Autofiller;

class GenericAutofiller extends AbstractAutofiller
{
    protected $label = 'Generic'; // @translate

    public function getResults($query, $lang = null): ?array
    {
        if (empty($this->options['sub']) || !in_array($this->options['sub'], ['json', 'xml'])
            || empty($this->options['url']) || !filter_var($this->options['url'], FILTER_VALIDATE_URL)
            || empty($this->options['query']) || strpos($this->options['query'], '{query}') === false
        ) {
            return null;
        }

        // TODO Manage language.
        // The max number of rows is the default one or the one set in the que query.
        $params = [];
        parse_str(strtr((string) $this->options['query'], ['{query}' => rawurlencode($query)]), $params);

        $response = $this->httpClient
            ->setUri($this->options['url'])
            ->setParameterGet($params)
            ->send();
        if (!$response->isSuccess()) {
            return null;
        }

        $body = $response->getBody();

        // Prepare mapper one time.
        $this->mapper->setMapping($this->mapping);

        return $this->options['sub'] === 'json'
            ? $this->getResultsJson($body)
            : $this->getResultsXml($body);
    }

    protected function getResultsJson($content)
    {
        // Parse the JSON response.
        $results = json_decode($content, true);

        // Get the root if needed.
        foreach ($this->mapping as $key => $map) {
            if (!empty($map['to']['field']) && $map['to']['field'] === '{list}') {
                unset($this->mapping[$key]);
                $results = $this->mapper->extractSubArray($results, $map['from']);
                if (empty($results)) {
                    return [];
                }
                break;
            }
        }

        $defaultLabel = $this->services->get('MvcTranslator')->translate('[Result]');

        // Get all uris and prepare all data one time.
        $uriLabels = [];
        $uriData = [];
        foreach ($results as $result) {
            $metadata = $this->mapper->array($result);
            if (!$metadata) {
                continue;
            }
            if (empty($metadata['{__label__}'][0]['@value']) || $metadata['{__label__}'][0]['@value'] === '{__label__}') {
                unset($metadata['{__label__}']);
                $first = reset($metadata);
                $labelResult = $first[0]['@value'] ?? $defaultLabel;
            } else {
                $labelResult = $metadata['{__label__}'][0]['@value'];
                unset($metadata['{__label__}']);
            }
            $uriLabels[] = $labelResult;
            $uriData[] = $metadata;
        }

        return $this->finalizeSuggestions($uriLabels, $uriData, null);
    }

    protected function getResultsXml($content)
    {
        // Get the root if needed.
        $hasRoot = false;
        foreach ($this->mapping as $key => $map) {
            if (!empty($map['to']['field']) && $map['to']['field'] === '{list}') {
                $hasRoot = true;
                unset($this->mapping[$key]);
                $results = $this->mapper->extractSubArrayXml($content, $map['from']);
                if (empty($results)) {
                    return [];
                }
                break;
            }
        }

        if (!$hasRoot) {
            $results = [$content];
        }

        $defaultLabel = $this->services->get('MvcTranslator')->translate('[Result]');

        // Get all uris and prepare all data one time.
        $uriLabels = [];
        $uriData = [];
        foreach ($results as $result) {
            $metadata = $this->mapper->xml($result);
            if (!$metadata) {
                continue;
            }
            if (empty($metadata['{__label__}'][0]['@value']) || $metadata['{__label__}'][0]['@value'] === '{__label__}') {
                unset($metadata['{__label__}']);
                $first = reset($metadata);
                $labelResult = $first[0]['@value'] ?? $defaultLabel;
            } else {
                $labelResult = $metadata['{__label__}'][0]['@value'];
                unset($metadata['{__label__}']);
            }
            $uriLabels[] = $labelResult;
            $uriData[] = $metadata;
        }

        return $this->finalizeSuggestions($uriLabels, $uriData, null);
    }
}

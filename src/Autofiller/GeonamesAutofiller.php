<?php declare(strict_types=1);

namespace AdvancedResourceTemplate\Autofiller;

class GeonamesAutofiller extends AbstractAutofiller
{
    protected $label = 'Geonames'; // @translate

    public function getResults(string $query, ?string $lang = null): ?array
    {
        $params = $this->queryParams();
        if ($lang && !array_key_exists('lang', $params)) {
            // Geonames requires an ISO-639 2-letter language code.
            $params['lang'] = strtok($lang, '_');
        }
        $params['q'] = $query;
        $params['maxRows'] ??= 100;
        // The api requires a username, set in the param "query" of the mapping
        // ("username=xxx"). Some common ones are shared and limited per hour.
        $params['username'] ??= 'kdlinfo';

        // @see \ValueSuggest\Suggester\Geonames\GeonamesSuggest::getSuggestions()
        $response = $this->httpClient
            ->resetParameters()
            ->setUri('https://secure.geonames.org/searchJSON')
            ->setParameterGet($params)
            ->send();
        if (!$response->isSuccess()) {
            return null;
        }

        $results = json_decode($response->getBody(), true);
        if (!is_array($results) || !isset($results['geonames'])) {
            return null;
        }

        $uriLabels = [];
        $uriData = [];
        foreach ($results['geonames'] as $result) {
            if (empty($result['geonameId'])) {
                continue;
            }
            $metadata = $this->mapper->convert($result);
            if (!$metadata) {
                continue;
            }
            // Same uri than module Value Suggest.
            $uri = 'http://www.geonames.org/' . $result['geonameId'];
            $uriLabels[$uri] = $result['name'] ?? $result['toponymName'] ?? $uri;
            $uriData[$uri] = $metadata;
        }

        return $this->finalizeSuggestions($uriLabels, $uriData, 'valuesuggest:geonames:geonames');
    }
}

<?php declare(strict_types=1);

namespace AdvancedResourceTemplate\Autofiller;

use Laminas\Http\Client\Adapter\Curl as CurlAdapter;
use SimpleXMLElement;

class IdRefAutofiller extends AbstractAutofiller
{
    /**
     * Because there is a subquery for each result, the number of results is
     * limited.
     */
    const MAX_RESULTS = 30;

    /**
     * Max number of records fetched simultaneously.
     */
    const MAX_CONNECTIONS = 10;

    protected $label = 'IdRef'; // @translate

    /**
     * @see https://www.idref.fr/Sru/Solr
     */
    protected $types = [
        'idref' => [
            'label' => 'IdRef', // @translate
            'url' => 'https://www.idref.fr/Sru/Solr?wt=json&version=2.2&indent=on&fl=id,ppn_z,affcourt_r&q=all%3A',
        ],
        'idref:person' => [
            'label' => 'IdRef: Person names', // @translate
            'url' => 'https://www.idref.fr/Sru/Solr?wt=json&version=2.2&indent=on&fl=id,ppn_z,affcourt_z&q=persname_t%3A',
        ],
        'idref:corporation' => [
            'label' => 'IdRef: Collectivities', // @translate
            'url' => 'https://www.idref.fr/Sru/Solr?wt=json&version=2.2&indent=on&fl=id,ppn_z,affcourt_z&q=corpname_t%3A',
        ],
        'idref:conference' => [
            'label' => 'IdRef: Conferences', // @translate
            'url' => 'https://www.idref.fr/Sru/Solr?wt=json&version=2.2&indent=on&fl=id,ppn_z,affcourt_z&q=conference_t%3A',
        ],
        'idref:subject' => [
            'label' => 'IdRef: Subjects', // @translate
            'url' => 'https://www.idref.fr/Sru/Solr?wt=json&version=2.2&indent=on&fl=id,ppn_z,affcourt_z&q=subjectheading_t%3A',
        ],
        'idref:rameau' => [
            'label' => 'IdRef: Subjects Rameau', // @translate
            'url' => 'https://www.idref.fr/Sru/Solr?wt=json&version=2.2&indent=on&fl=id,ppn_z,affcourt_z&q=recordtype_z%3Aj%20AND%20subjectheading_t%3A',
        ],
        'idref:fmesh' => [
            'label' => 'IdRef: Subjects F-MeSH', // @translate
            'url' => 'https://www.idref.fr/Sru/Solr?wt=json&version=2.2&indent=on&fl=id,ppn_z,affcourt_z&q=recordtype_z%3At%20AND%20subjectheading_t%3A',
        ],
        'idref:geo' => [
            'label' => 'IdRef: Geography', // @translate
            'url' => 'https://www.idref.fr/Sru/Solr?wt=json&version=2.2&indent=on&fl=id,ppn_z,affcourt_z&q=geogname_t%3A',
        ],
        'idref:family' => [
            'label' => 'IdRef: Family names', // @translate
            'url' => 'https://www.idref.fr/Sru/Solr?wt=json&version=2.2&indent=on&fl=id,ppn_z,affcourt_z&q=famname_t%3A',
        ],
        'idref:title' => [
            'label' => 'IdRef: Uniform titles', // @translate
            'url' => 'https://www.idref.fr/Sru/Solr?wt=json&version=2.2&indent=on&fl=id,ppn_z,affcourt_z&q=uniformtitle_t%3A',
        ],
        'idref:authorTitle' => [
            'label' => 'IdRef: Authors-Titles', // @translate
            'url' => 'https://www.idref.fr/Sru/Solr?wt=json&version=2.2&indent=on&fl=id,ppn_z,affcourt_z&q=nametitle_t%3A',
        ],
        'idref:trademark' => [
            'label' => 'IdRef: Trademarks', // @translate
            'url' => 'https://www.idref.fr/Sru/Solr?wt=json&version=2.2&indent=on&fl=id,ppn_z,affcourt_z&q=trademark_t%3A',
        ],
        'idref:ppn' => [
            'label' => 'IdRef: PPN id', // @translate
            'url' => 'https://www.idref.fr/Sru/Solr?wt=json&version=2.2&indent=on&fl=id,ppn_z,affcourt_z&q=ppn_z%3A',
        ],
        'idref:library' => [
            'label' => 'IdRef: Library registry (RCR)', // @translate
            'url' => 'https://www.idref.fr/Sru/Solr?wt=json&version=2.2&indent=on&fl=id,ppn_z,affcourt_r&q=rcr_t%3A',
        ],
    ];

    public function getLabel(): string
    {
        $service = $this->serviceName();
        return empty($this->options['label']) && !empty($this->options['mapping_is_default']) && isset($this->types[$service])
            ? $this->types[$service]['label']
            : parent::getLabel();
    }

    public function getResults(string $query, ?string $lang = null): ?array
    {
        $service = $this->serviceName();
        if (empty($this->types[$service])) {
            return null;
        }

        // Convert the query into a Solr query.
        $query = strpos($query, ' ')
            ? '(' . implode('%20AND%20', array_map('rawurlencode', explode(' ', $query))) . ')'
            : rawurlencode($query);
        $extraQuery = $this->mappingParam('query');
        if ($extraQuery) {
            $query .= '&' . $extraQuery;
        }

        $response = $this->httpClient
            ->resetParameters()
            ->setUri($this->types[$service]['url'] . $query . '&rows=' . self::MAX_RESULTS)
            ->send();
        if (!$response->isSuccess()) {
            return null;
        }

        $results = json_decode($response->getBody(), true);
        if (empty($results['response']['docs'])) {
            return [];
        }

        $labels = [];
        foreach ($results['response']['docs'] as $result) {
            if (empty($result['ppn_z'])) {
                continue;
            }
            // "affcourt" may be missing in some results (empty words).
            $label = $result['affcourt_r'] ?? $result['affcourt_z'] ?? $result['ppn_z'];
            $labels[$result['ppn_z']] = is_array($label) ? reset($label) : $label;
        }
        $labels = array_slice($labels, 0, self::MAX_RESULTS, true);

        // The results are only labels and an id, so get the full records.
        $uriLabels = [];
        $uriData = [];
        foreach ($this->fetchRecords(array_map('strval', array_keys($labels))) as $ppn => $record) {
            $metadata = $record ? $this->mapper->convert($record) : [];
            if ($metadata) {
                $uri = 'https://www.idref.fr/' . $ppn;
                $uriLabels[$uri] = $labels[$ppn];
                $uriData[$uri] = $metadata;
            }
        }

        return $this->finalizeSuggestions($uriLabels, $uriData, 'valuesuggest:' . $service);
    }

    protected function serviceName(): string
    {
        return empty($this->options['sub']) ? 'idref' : 'idref:' . $this->options['sub'];
    }

    /**
     * Fetch the records simultaneously when possible, since the remote service
     * may be slow.
     *
     * @return SimpleXMLElement[]|null[] Records by ppn.
     */
    protected function fetchRecords(array $ppns): array
    {
        $adapter = $this->httpClient->getAdapter();
        if (!$adapter instanceof CurlAdapter || !function_exists('curl_multi_init')) {
            $records = [];
            foreach ($ppns as $ppn) {
                $records[$ppn] = $this->fetchRecord($ppn);
            }
            return $records;
        }

        // Reuse the config of the http client (proxy, certificates, etc.).
        $config = $adapter->getConfig();
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => (int) ($config['timeout'] ?? 10),
        ];
        if (!empty($config['sslcafile'])) {
            $options[CURLOPT_CAINFO] = $config['sslcafile'];
        }
        if (!empty($config['sslcapath'])) {
            $options[CURLOPT_CAPATH] = $config['sslcapath'];
        }
        $options = ($config['curloptions'] ?? []) + $options;

        $multi = curl_multi_init();
        curl_multi_setopt($multi, CURLMOPT_MAX_TOTAL_CONNECTIONS, self::MAX_CONNECTIONS);
        $handles = [];
        foreach ($ppns as $ppn) {
            $handle = curl_init($this->recordUrl($ppn));
            curl_setopt_array($handle, $options);
            curl_multi_add_handle($multi, $handle);
            $handles[$ppn] = $handle;
        }

        do {
            $status = curl_multi_exec($multi, $running);
            if ($running) {
                curl_multi_select($multi);
            }
        } while ($running && $status === CURLM_OK);

        $records = [];
        foreach ($handles as $ppn => $handle) {
            $records[$ppn] = curl_getinfo($handle, CURLINFO_RESPONSE_CODE) === 200
                ? $this->parseXml((string) curl_multi_getcontent($handle))
                : null;
            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);
        }
        curl_multi_close($multi);

        return $records;
    }

    protected function fetchRecord(string $ppn): ?SimpleXMLElement
    {
        $response = $this->httpClient
            ->resetParameters()
            ->setUri($this->recordUrl($ppn))
            ->send();
        return $response->isSuccess()
            ? $this->parseXml($response->getBody())
            : null;
    }

    protected function recordUrl(string $ppn): string
    {
        return 'https://www.idref.fr/' . rawurlencode($ppn) . '.xml';
    }

    protected function parseXml(string $xml): ?SimpleXMLElement
    {
        $record = @simplexml_load_string($xml);
        return $record ?: null;
    }
}

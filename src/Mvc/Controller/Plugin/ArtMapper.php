<?php declare(strict_types=1);

namespace AdvancedResourceTemplate\Mvc\Controller\Plugin;

use ArrayObject;
use Common\Stdlib\EasyMeta;
use DOMDocument;
use DOMXPath;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Omeka\Api\Manager as ApiManager;
use Omeka\Mvc\Controller\Plugin\Translate;

/**
 * Extract data from a string with a mapping.
 *
 * @deprecated Use Bulk Import meta mapper.
 * @see \BulkImport\Mvc\Controller\Plugin\MetaMapper
 * @todo Merge with \BulkImport\Mvc\Controller\Plugin\MetaMapper.
 */
class ArtMapper extends AbstractPlugin
{
    /**
     * @var \Omeka\Api\Manager
     */
    protected $api;

    /**
     * @var \Common\Stdlib\EasyMeta
     */
    protected $easyMeta;

    /**
     * @var \Omeka\Mvc\Controller\Plugin\Translate
     */
    protected $translate;

    /**
     * Normalize a mapping.
     *
     * Mapping is either a list of xpath or json path mapped with properties:
     * [
     *     [/xpath/to/data => [field => dcterms:title]],
     *     [object.to.data => [field => dcterms:title]],
     * ]
     *
     * @var array
     */
    protected $mapping = [];

    /**
     * Only extract metadata, don't map them.
     *
     * @var bool
     */
    protected $isSimpleExtract = false;

    /**
     * @var bool
     */
    protected $isInternalSource = false;

    /**
     * @var ArrayObject
     */
    protected $result;

    /**
     * @var string
     */
    protected $lastResultValue;

    public function __construct(
        ApiManager $api,
        EasyMeta $easyMeta,
        Translate $translate
    ) {
        // Don't use api plugin, because a form may be set and will be removed
        // when recalled (nearly anywhere), even for a simple read.
        $this->api = $api;
        $this->easyMeta = $easyMeta;
        $this->translate = $translate;
    }

    public function __invoke(): self
    {
        return $this;
    }

    public function setMapping(array $mapping): self
    {
        $this->mapping = $this->normalizeMapping($mapping);
        return $this;
    }

    public function setIsSimpleExtract($isSimpleExtract): self
    {
        $this->isSimpleExtract = (bool) $isSimpleExtract;
        return $this;
    }

    /**
     * Allow to manage id for internal resources.
     */
    public function setIsInternalSource($isInternalSource): self
    {
        $this->isInternalSource = (bool) $isInternalSource;
        return $this;
    }

    /**
     * Extract data from an url that returns a json.
     *
     * @param string $url
     * @return array A resource array by property, suitable for api creation
     * or update.
     */
    public function urlArray(string $url): array
    {
        $content = file_get_contents($url);
        $content = json_decode($content, true);
        if (!is_array($content)) {
            return [];
        }
        return $this->array($content);
    }

    /**
     * Extract data from an url that returns an xml.
     *
     * @param string $url
     * @return array A resource array by property, suitable for api creation
     * or update.
     */
    public function urlXml(string $url): array
    {
        $content = file_get_contents($url);
        if (empty($content)) {
            return [];
        }
        return $this->xml($content);
    }

    /**
     * Extract data from an array.
     *
     * @param array $input Array of metadata.
     * @return array A resource array by property, suitable for api creation
     * or update.
     */
    public function array(array $input): array
    {
        if (empty($this->mapping)) {
            return [];
        }

        // TODO Factorize with extractSingleValue().
        $this->result = new ArrayObject([], ArrayObject::ARRAY_AS_PROPS);

        $input = $this->flatArray($input);

        foreach ($this->mapping as $map) {
            $target = $map['to'];
            if (!empty($target['replace'])) {
                $target['replace'] = array_fill_keys($target['replace'], '');
                foreach ($target['replace'] as $query => &$replacement) {
                    if (in_array($query, ['{__value__}', '{__label__}'])) {
                        continue;
                    }
                    $query = mb_substr($query, 1, -1);
                    if (isset($input[$query])) {
                        $replacement = $input[$query];
                    }
                }
                unset($replacement);
            }

            $query = $map['from'];
            if ($query === '~') {
                $value = '';
            } else {
                if (!isset($input[$query])) {
                    continue;
                }
                $value = $input[$query];
            }

            $this->isSimpleExtract
                ? $this->simpleExtract($value, $target, $query)
                : $this->appendValueToTarget($value, $target);
        }

        return $this->result->exchangeArray([]);
    }

    /**
     * Extract data from a xml string with a mapping.
     *
     * @return array A resource array by property, suitable for api creation
     * or update.
     */
    public function xml(string $xml): array
    {
        if (empty($this->mapping)) {
            return [];
        }

        // Check if the xml is fully formed.
        $xml = trim($xml);
        if (strpos($xml, '<?xml ') !== 0) {
            $xml = '<?xml version="1.1" encoding="utf-8"?>' . $xml;
        }

        $this->result = new ArrayObject([], ArrayObject::ARRAY_AS_PROPS);

        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->loadXML($xml);
        $xpath = new DOMXPath($doc);

        // Register all namespaces to allow prefixes.
        $xpathN = new DOMXPath($doc);
        foreach ($xpathN->query('//namespace::*') as $node) {
            $xpath->registerNamespace($node->prefix, $node->nodeValue);
        }

        foreach ($this->mapping as $map) {
            $target = $map['to'];
            if (!empty($target['replace'])) {
                $target['replace'] = array_fill_keys($target['replace'], '');
                foreach ($target['replace'] as $query => &$value) {
                    if (in_array($query, ['{__value__}', '{__label__}'])) {
                        continue;
                    }
                    $nodeList = $xpath->query(mb_substr($query, 1, -1));
                    if (!$nodeList || !$nodeList->length) {
                        continue;
                    }
                    $value = $nodeList->item(0)->nodeValue;
                }
                unset($value);
            }

            $query = $map['from'];
            if ($query === '~') {
                $value = '';
                $this->isSimpleExtract
                    ? $this->simpleExtract($value, $target, $query)
                    : $this->appendValueToTarget($value, $target);
            } else {
                $nodeList = $xpath->query($query);
                if (!$nodeList || !$nodeList->length) {
                    continue;
                }
                // The answer has many nodes.
                foreach ($nodeList as $node) {
                    $this->isSimpleExtract
                        ? $this->simpleExtract($node->nodeValue, $target, $query)
                        : $this->appendValueToTarget($node->nodeValue, $target);
                }
            }
        }

        return $this->result->exchangeArray([]);
    }

    public function extractSubArray(array $array, string $path): ?array
    {
        foreach (explode('.', $path) as $subpath) {
            if (isset($array[$subpath])) {
                $array = $array[$subpath];
            } else {
                return null;
            }
        }
        return is_array($array) ? $array : null;
    }

    public function extractSubArrayXml(string $xml, string $path): ?array
    {
        // Check if the xml is fully formed.
        $xml = trim($xml);
        if (strpos($xml, '<?xml ') !== 0) {
            $xml = '<?xml version="1.1" encoding="utf-8"?>' . $xml;
        }

        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->loadXML($xml);
        $xpath = new DOMXPath($doc);

        // Register all namespaces to allow prefixes.
        $xpathN = new DOMXPath($doc);
        foreach ($xpathN->query('//namespace::*') as $node) {
            $xpath->registerNamespace($node->prefix, $node->nodeValue);
        }

        $nodeList = $xpath->query($path);
        if (!$nodeList || !$nodeList->length) {
            return null;
        }

        $array = [];
        foreach ($nodeList as $node) {
            $array[] = $node->C14N();
        }
        return $array;
    }

    /**
     * Extract a value from a source and a path and transform it with mapping.
     *
     * @param array $map The map array with keys "from" and "to".
     * @return array A list of value.
     */
    public function extractValueDirect($source, $map): ?array
    {
        $this->result = new ArrayObject([], ArrayObject::ARRAY_AS_PROPS);
        $this->lastResultValue = null;

        $input = $this->flatArray($source);
        $target = $map['to'];
        if (!empty($target['replace'])) {
            $target['replace'] = array_fill_keys($target['replace'], '');
            foreach ($target['replace'] as $query => &$replacement) {
                if (in_array($query, ['{__value__}', '{__label__}'])) {
                    continue;
                }
                $query = mb_substr($query, 1, -1);
                if (isset($input[$query])) {
                    $replacement = $input[$query];
                }
            }
            unset($replacement);
        }

        $query = $map['from'];
        if ($query === '~') {
            $value = '';
        } else {
            if (!isset($input[$query])) {
                return null;
            }
            $value = $input[$query];
        }

        $this->isSimpleExtract
            ? $this->simpleExtract($value, $target, $query)
            : $this->appendValueToTarget($value, $target);

        return $this->result->exchangeArray([]);
    }

    /**
     * Extract a value from a source and a path and transform it with mapping.
     *
     * @param array $map The map array with keys "from" and "to".
     * @return array A list of value.
     */
    public function extractValueOnly($source, $map)
    {
        $this->extractValueDirect($source, $map);
        return $this->lastResultValue;
    }

    protected function simpleExtract($value, $target, $source): void
    {
        $this->result[] = [
            'field' => $source,
            'target' => $target,
            'value' => $value,
        ];
    }

    protected function appendValueToTarget($value, $target): void
    {
        $v = $target;
        unset($v['field'], $v['pattern'], $v['replace']);

        if (!empty($target['pattern'])) {
            $transformed = $target['pattern'];
            if (!empty($target['replace'])) {
                $target['replace']['{__value__}'] = $value;
                $target['replace']['{__label__}'] = $value;
                $transformed = strtr($target['pattern'], $target['replace']);
            }
            if (!empty($target['twig'])) {
                $target['pattern'] = $transformed;
                $transformed = $this->twig($value, $target);
            }
            $value = $transformed;
        }

        $mainType = $this->easyMeta->dataTypeMain($v['type']) ?? null;

        switch ($mainType) {
            case 'resource':
                // The mapping from an external service cannot be an internal
                // resource.
                // Nevertheless, for internal source, the result is checked and
                // kept below.
                if ($this->isInternalSource) {
                    try {
                        $this->api->read('resources', ['id' => $value], ['initialize' => false, 'finalize' => false]);
                    } catch (\Exception $e) {
                        $this->lastResultValue = $value;
                        return;
                    }
                }
                break;
            case 'uri':
                $v['@id'] = $value;
                $this->result[$target['field']][] = $v;
                break;
            case 'literal':
            default:
                $v['@value'] = $value;
                $this->result[$target['field']][] = $v;
                break;
        }

        $this->lastResultValue = $value;
    }

    /**
     * Convert a value into another value via twig filters.
     *
     * Only some common filters and some filter arguments are managed, and some
     * special functions for dates and index uris.
     *
     * @todo Check for issues with separators or parenthesis included in values.
     * @todo Separate preparation and process.
     * @fixme The args extractor does not manage escaped quote and double quote in arguments.
     *
     * Adapted from BulkImport.
     * @see \BulkImport\Stdlib\TwigTrait
     */
    protected function twig($value, $target): string
    {
        // For adaptation with BulkImport.

        // Full pattern and list of queries.
        $pattern = $target['pattern'] ?? '';
        $twig = $target['twig'] ?? [];
        if (!$pattern || !$twig) {
            return $value;
        }

        $twigVars = [
            'value' => $value,
        ];
        $patternVars = 'value|';
        $twigHasReplace = [];
        $replace = $target['replace'] ?? [];

        // Copy from BulkImport.

        $extractList = function (string $args, array $keys = []) use ($patternVars, $twigVars): array {
            $matches = [];
            preg_match_all('~\s*(?<args>' . $patternVars . '"[^"]*?"|\'[^\']*?\'|[+-]?(?:\d*\.)?\d+)\s*,?\s*~', $args, $matches);
            $args = array_map(function ($arg) use ($twigVars) {
                // If this is a var, take it, else this is a string or a number,
                // so remove the quotes if any.
                return $twigVars['{{ ' . $arg . ' }}'] ?? (is_numeric($arg) ? $arg : mb_substr($arg, 1, -1));
            }, $matches['args']);
            $countKeys = count($keys);
            return $countKeys
                ? array_combine($keys, count($args) >= $countKeys ? array_slice($args, 0, $countKeys) : array_pad($args, $countKeys, ''))
                : $args;
        };

        $extractAssociative = function (string $args) use ($patternVars, $twigVars): array {
            // TODO Improve the regex to extract keys and values directly.
            $matches = [];
            preg_match_all('~\s*(?<args>' . $patternVars . '"[^"]*?"|\'[^\']*?\'|[+-]?(?:\d*\.)?\d+)\s*,?\s*~', $args, $matches);
            $output = [];
            foreach (array_chunk($matches['args'], 2) as $keyValue) {
                if (count($keyValue) === 2) {
                    // The key cannot be a value, but may be numeric.
                    $key = is_numeric($keyValue[0]) ? $keyValue[0] : mb_substr($keyValue[0], 1, -1);
                    $value = $twigVars['{{ ' . $keyValue[1] . ' }}'] ?? (is_numeric($keyValue[1]) ? $keyValue[1] : mb_substr($keyValue[1], 1, -1));
                    $output[$key] = $value;
                }
            }
            return $output;
        };

        /**
         * @param mixed $v Value to process, generally a string but may be an array.
         * @param string $filter The full function with arguments, like "slice(1, 4)".
         * @return string|array
         */
        $twigProcess = function ($v, string $filter) use ($twigVars, $extractList, $extractAssociative) {
            $matches = [];
            if (preg_match('~\s*(?<function>[a-zA-Z0-9_]+)\s*\(\s*(?<args>.*?)\s*\)\s*~U', $filter, $matches) > 0) {
                $function = $matches['function'];
                $args = $matches['args'];
            } else {
                $function = $filter;
                $args = '';
            }
            // Most of the time, a string is required, but a function can return
            // an array. Only some functions can manage an array.
            $w = (string) (is_array($v) ? reset($v) : $v);
            switch ($function) {
                case 'abs':
                    $v = is_numeric($w) ? (string) abs($w) : $w;
                    break;

                case 'capitalize':
                    $v = ucfirst($w);
                    break;

                case 'date':
                    $format = $args;
                    try {
                        $v = $format === ''
                            ? @strtotime($w)
                            : @date($format, @strtotime($w));
                    } catch (\Exception $e) {
                        // Nothing: keep value.
                    }
                    break;

                case 'e':
                case 'escape':
                    $v = htmlspecialchars($w, ENT_COMPAT | ENT_HTML5);
                    break;

                case 'first':
                    $v = is_array($v) ? $w : mb_substr($v, 0, 1);
                    break;

                case 'format':
                    $arga = $extractList($args);
                    if ($arga) {
                        try {
                            $v = @vsprintf($w, $arga);
                        } catch (\Exception $e) {
                            // Nothing: keep value.
                        }
                    }
                    break;

                // The twig filter is "join", but here "implode" is a function.
                case 'implode':
                    $arga = $extractList($args);
                    if (count($arga)) {
                        $delimiter = array_shift($arga);
                        $v = implode($delimiter, $arga);
                    } else {
                        $v = '';
                    }
                    break;

                // Implode only real values, not empty string.
                case 'implodev':
                    $arga = $extractList($args);
                    if (count($arga)) {
                        $arga = array_filter($arga, 'strlen');
                        // The string avoids strict type issue with empty array.
                        $delimiter = (string) array_shift($arga);
                        $v = implode($delimiter, $arga);
                    } else {
                        $v = '';
                    }
                    break;

                case 'last':
                    $v = is_array($v) ? (string) end($v) : mb_substr((string) $v, -1);
                    break;

                case 'length':
                    $v = (string) (is_array($v) ? count($v) : mb_strlen((string) $v));
                    break;

                case 'lower':
                    $v = mb_strtolower($w);
                    break;

                case 'replace':
                    $arga = $extractAssociative($args);
                    if ($arga) {
                        $v = strtr($w, $arga);
                    }
                    break;

                case 'slice':
                    $arga = $extractList($args);
                    $start = (int) ($arga[0] ?? 0);
                    $length = (int) ($arga[1] ?? 1);
                    $v = is_array($v)
                        ? array_slice($v, $start, $length, !empty($arga[2]))
                        : mb_substr($w, $start, $length);
                    break;

                case 'split':
                    $arga = $extractList($args);
                    $delimiter = $arga[0] ?? '';
                    $limit = (int) ($arga[1] ?? 1);
                    $v = strlen($delimiter)
                        ? explode($delimiter, $w, $limit)
                        : str_split($w, $limit);
                    break;

                case 'striptags':
                    $v = strip_tags($w);
                    break;

                case 'table':
                    // table() (included).
                    $first = mb_substr($args, 0, 1);
                    if ($first === '{') {
                        $table = $extractAssociative(trim(mb_substr($args, 1, -1)));
                        if ($table) {
                            $v = $table[$w] ?? $w;
                        }
                    }
                    // table() (named).
                    else {
                        $name = $first === '"' || $first === "'" ? mb_substr($args, 1, -1) : $args;
                        $v = $this->tables[$name][$w] ?? $w;
                    }
                    break;

                case 'title':
                    $v = ucwords($w);
                    break;

                case 'trim':
                    $arga = $extractList($args);
                    $characterMask = $arga[0] ?? '';
                    if (!strlen($characterMask)) {
                        $characterMask = " \t\n\r\0\x0B";
                    }
                    $side = $arga[1] ?? '';
                    // Side is "both" by default.
                    if ($side === 'left') {
                        $v = ltrim($w, $characterMask);
                    } elseif ($side === 'right') {
                        $v = rtrim($w, $characterMask);
                    } else {
                        $v = trim($w, $characterMask);
                    }
                    break;

                case 'upper':
                    $v = mb_strtoupper($w);
                    break;

                case 'url_encode':
                    $v = rawurlencode($w);
                    break;

                // Special filters and functions to manage common values.

                case 'dateIso':
                    // "d1605110512" => "1605-11-05T12" (date iso).
                    // "[1984]-" => kept.
                    // Missing numbers may be set as "u", but this is not
                    // manageable as iso 8601.
                    // The first character may be a space to manage Unimarc.
                    $v = $w;
                    if (mb_strlen($v) && mb_strpos($v, 'u') === false) {
                        $firstChar = mb_substr($v, 0, 1);
                        if (in_array($firstChar, ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '-', '+', 'c', 'd', ' '])) {
                            if (in_array($firstChar, ['-', '+', 'c', 'd', ' '])) {
                                $d = $firstChar === '-' || $firstChar === 'c' ? '-' : '';
                                $v = mb_substr($v, 1);
                            } else {
                                $d = '';
                            }
                            $v = $d
                                . mb_substr($v, 0, 4) . '-' . mb_substr($v, 4, 2) . '-' . mb_substr($v, 6, 2)
                                . 'T' . mb_substr($v, 8, 2) . ':' . mb_substr($v, 10, 2) . ':' . mb_substr($v, 12, 2);
                            $v = rtrim($v, '-:T |#');
                        }
                    }
                    break;

                case 'dateSql':
                    // Unimarc 005.
                    // "19850901141236.0" => "1985-09-01 14:12:36" (date sql).
                    $v = trim($w);
                    $v = mb_substr($v, 0, 4) . '-' . mb_substr($v, 4, 2) . '-' . mb_substr($v, 6, 2)
                        . ' ' . mb_substr($v, 8, 2) . ':' . mb_substr($v, 10, 2) . ':' . mb_substr($v, 12, 2);
                    break;

                case 'isbdName':
                    // isbdName(a, b, c, d, f, g, k, o, p, 5) (function).
                    /* Unimarc 700 et suivants :
                    $a Élément d’entrée
                    $b Partie du nom autre que l’élément d’entrée
                    $c Eléments ajoutés aux noms autres que les dates
                    $d Chiffres romains
                    $f Dates
                    $g Développement des initiales du prénom
                    $k Qualificatif pour l’attribution
                    $o Identifiant international du nom
                    $p Affiliation / adresse
                    $5 Institution à laquelle s’applique la zone
                     */
                    $arga = $extractList($args, ['a', 'b', 'c', 'd', 'f', 'g', 'k', 'o', 'p', '5']);
                    // @todo Improve isbd for names.
                    $v = $arga['a']
                        . ($arga['b'] ? ', ' . $arga['b'] : '')
                        . ($arga['g'] ? ' (' . $arga['g'] . ')' : '')
                        . ($arga['d'] ? ', ' . $arga['d'] : '')
                        . (
                            $arga['f']
                            ? ' (' . $arga['f']
                                . ($arga['c'] ? ' ; ' . $arga['c'] : '')
                                . ($arga['k'] ? ' ; ' . $arga['k'] : '')
                                . ')'
                            : (
                                $arga['c']
                                    ? (' (' . $arga['c'] . ($arga['k'] ? ' ; ' . $arga['k'] : '') . ')')
                                    : ($arga['k'] ? ' (' . $arga['k'] . ')' : '')
                            )
                        )
                        . ($arga['o'] ? ' {' . $arga['o'] . '}' : '')
                        . ($arga['p'] ? ', ' . $arga['p'] : '')
                        . ($arga['5'] ? ', ' . $arga['5'] : '')
                    ;
                    break;

                case 'isbdNameColl':
                    // isbdNameColl(a, b, c, d, e, f, g, h, o, p, r, 5) (function).
                    /* Unimarc 710/720/740 et suivants :
                    $a Élément d’entrée
                    $b Subdivision
                    $c Élément ajouté au nom ou qualificatif
                    $d Numéro de congrès et/ou numéro de session de congrès
                    $e Lieu du congrès
                    $f Date du congrès
                    $g Élément rejeté
                    $h Partie du nom autre que l’élément d’entrée et autre que l’élément rejeté
                    $o Identifiant international du nom
                    $p Affiliation / adresse
                    $r Partie ou rôle joué
                    $5 Institution à laquelle s’applique la zone
                    // Pour mémoire.
                    $3 Identifiant de la notice d’autorité
                    $4 Code de fonction
                     */
                    $arga = $extractList($args, ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'o', 'p', 'r', '5']);
                    // @todo Improve isbd for organizations.
                    $v = $arga['a']
                        . ($arga['b'] ? ', ' . $arga['b'] : '')
                        . ($arga['g']
                            ? ' (' . $arga['g'] . ($arga['h'] ? ' ; ' . $arga['h'] . '' : '') . ')'
                            : ($arga['h'] ? ' (' . $arga['h'] . ')' : ''))
                        . ($arga['d'] ? ', ' . $arga['d'] : '')
                        . ($arga['e'] ? ', ' . $arga['e'] : '')
                        . (
                            $arga['f']
                                ? ' (' . $arga['f']
                                    . ($arga['c'] ? ' ; ' . $arga['c'] : '')
                                    . ')'
                                : ($arga['c'] ? (' (' . $arga['c'] . ')') : '')
                        )
                        . ($arga['o'] ? ' {' . $arga['o'] . '}' : '')
                        . ($arga['p'] ? ', ' . $arga['p'] : '')
                        . ($arga['r'] ? ', ' . $arga['r'] : '')
                        . ($arga['5'] ? ', ' . $arga['5'] : '')
                    ;
                    break;

                case 'isbdMark':
                    /* Unimarc 716 :
                    $a Élément d’entrée
                    $c Qualificatif
                    $f Dates
                      */
                    // isbdMark(a, b, c) (function).
                    $arga = $extractList($args, ['a', 'b', 'c']);
                    // @todo Improve isbd for marks.
                    $v = $arga['a']
                        . ($arga['b'] ? ', ' . $arga['b'] : '')
                        . ($arga['c'] ? (' (' . $arga['c'] . ')') : '')
                    ;
                    break;

                case 'unimarcIndex':
                    $arga = $extractList($args);
                    $index = $arga[0] ?? '';
                    if ($index) {
                        // Unimarc Index uri (filter or function).
                        $code = count($arga) === 1 ? $w : ($arga[1] ?? '');
                        // Unimarc Annexe G.
                        // @link https://www.transition-bibliographique.fr/wp-content/uploads/2018/07/AnnexeG-5-2007.pdf
                        switch ($index) {
                            case 'unimarc/a':
                                $v = 'Unimarc/A : ' . $code;
                                break;
                            case 'rameau':
                                $v = 'https://data.bnf.fr/ark:/12148/cb' . $code . $this->noidCheckBnf('cb' . $code);
                                break;
                            default:
                                $v = $index . ' : ' . $code;
                                break;
                        }
                    }
                    break;

                case 'unimarcCoordinates':
                    // "w0241207" => "W 24°12’7”".
                    // Hemisphere "+" / "-" too.
                    $v = $w;
                    $firstChar = mb_strtoupper(mb_substr($v, 0, 1));
                    $mappingChars = ['+' => 'N', '-' => 'S', 'W' => 'W', 'E' => 'E', 'N' => 'N', 'S' => 'S'];
                    $v = ($mappingChars[$firstChar] ?? '?') . ' '
                        . intval(mb_substr($v, 1, 3)) . '°'
                        . intval(mb_substr($v, 4, 2)) . '’'
                        . intval(mb_substr($v, 6, 2)) . '”';
                    break;

                case 'unimarcCoordinatesHexa':
                    $v = $w;
                    $v = mb_substr($v, 0, 2) . '°' . mb_substr($v, 2, 2) . '’' . mb_substr($v, 4, 2) . '”';
                    break;

                case 'unimarcTimeHexa':
                    // "150027" => "15h0m27s".
                    $v = $w;
                    $h = (int) trim(mb_substr($v, 0, 2));
                    $m = (int) trim(mb_substr($v, 2, 2));
                    $s = (int) trim(mb_substr($v, 4, 2));
                    $v = ($h ? $h . 'h' : '')
                        . ($m ? $m . 'm' : ($h && $s ? '0m' : ''))
                        . ($s ? $s . 's' : '');
                    break;

                // This is not a reserved keyword, so check for a variable.
                case 'value':
                default:
                    $v = $twigVars['{{ ' . $filter . ' }}'] ?? $twigVars[$filter] ?? $v;
                    break;
            }
            return is_array($v)
                ? $v
                : (string) $v;
        };

        $twigReplace = [];
        $twigPatterns = array_flip($twig);
        $hasReplace = !empty($replace);
        foreach ($twig as $query) {
            $hasReplaceQuery = $hasReplace && !empty($twigHasReplace[$twigPatterns[$query]]);
            $v = '';
            $filters = array_filter(array_map('trim', explode('|', mb_substr((string) $query, 3, -3))));
            // The first filter may not be a filter, but a variable. A variable
            // cannot be a reserved keyword.
            foreach ($filters as $filter) {
                $v = $hasReplaceQuery
                    ? $twigProcess($v, strtr($filter, $replace))
                    : $twigProcess($v, $filter);
            }
            // A twig pattern may return an array.
            if (is_array($v)) {
                $v = (string) reset($v);
            }
            if ($hasReplaceQuery) {
                $twigReplace[strtr($query, $replace)] = $v;
            } else {
                $twigReplace[$query] = $v;
            }
        }
        return strtr($pattern, $twigReplace);
    }

    protected function normalizeMapping(array $mapping): array
    {
        $translate = $this->translate;
        foreach ($mapping as &$map) {
            $to = &$map['to'];
            $to['property_id'] = $this->easyMeta->propertyId($to['field']);
            if (empty($to['type'])) {
                $to['type'] = 'literal';
            }
            if (empty($to['property_label'])) {
                $to['property_label'] = $translate($this->easyMeta->propertyLabel($to['field']));
            }
        }
        return $mapping;
    }

    /**
     * Create a flat array from a recursive array.
     *
     * @example
     * ```
     * // The following recursive array:
     * [
     *     'video' => [
     *         'data.format' => 'jpg',
     *         'bits_per_sample' => 24,
     *     ],
     * ]
     * // is converted into:
     * [
     *     'video.data\.format' => 'jpg',
     *     'video.bits_per_sample' => 24,
     * ]
     * ```
     *
     * @see \BulkImport\Stdlib\MetaMapper::flatArray()
     * @see \ValueSuggestAny\Suggester\JsonLd\JsonLdSuggester::flatArray()
     * @todo Factorize flatArray() between modules.
     */
    protected function flatArray(?array $array): array
    {
        // Quick check.
        if (empty($array)) {
            return [];
        }
        if (array_filter($array, 'is_scalar') === $array) {
            return $array;
        }
        $flatArray = [];
        $this->_flatArray($array, $flatArray);
        return $flatArray;
    }

    /**
     * Recursive helper to flat an array with separator ".".
     *
     * @todo Find a way to keep the last level of array (list of subjects…).
     */
    private function _flatArray(array &$array, &$flatArray, $keys = null): void
    {
        foreach ($array as $key => $value) {
            $nKey = strtr((string) $key, ['.' => '\.', '\\' => '\\\\']);
            if (is_array($value)) {
                $this->_flatArray($value, $flatArray, $keys . '.' . $nKey);
            } else {
                $flatArray[trim($keys . '.' . $nKey, '.')] = $value;
            }
        }
    }
}

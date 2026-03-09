<?php declare(strict_types=1);

namespace AdvancedResourceTemplate\Mvc\Controller\Plugin;

use Laminas\Mvc\Controller\Plugin\AbstractPlugin;

class FieldNameToProperty extends AbstractPlugin
{
    /**
     * Convert a field name as a property value array.
     *
     * @todo Replace with AutomapFields or MetaMapper from module BulkImport.
     *
     * It returns the property name and identified metadata. For example,
     * `dcterms:title @fr-fr ^^literal §private ~pattern for the {__value__} with {/record/data}`
     * will be converted into:
     * [
     *     'field' => 'dcterms:title',
     *     'type' => 'literal',
     *     '@language' => 'fr-fr',
     *     'is_public' => false,
     *     'pattern' => 'pattern for the {__value__} with {/record/data}',
     * ]
     *
     * The format of each part is checked, but not if it has a meaning. The
     * property id is not checked, but the term is required. If present, the
     * pattern must be the last part.
     *
     * @see \BulkImport\Mvc\Controller\Plugin\AutomapFields
     *
     * @param string $field
     * @return array|null
     */
    public function __invoke($field): ?array
    {
        $base = [
            'field' => null,
        ];
        $matches = [];
        $field = $this->trimUnicode($field);
        $fieldArray = array_filter(explode(' ', $field));
        foreach ($fieldArray as $part) {
            $first = mb_substr($part, 0, 1);
            if ($first === '~') {
                $base['pattern'] = trim(mb_substr($field, mb_strpos($field, '~', 1) + 1));
                // Use negative look behind/ahead to separate simple replace and
                // twig commands.
                if ($base['pattern']) {
                    if (preg_match_all('~(?<![\{])\{([^{}]+)\}(?!\})~', $base['pattern'], $matches) !== false) {
                        $base['replace'] = empty($matches[0]) ? [] : array_values(array_unique($matches[0]));
                    }
                    if (preg_match_all('~\{{2} ([^{}]+) \}{2}~', $base['pattern'], $matches) !== false) {
                        $base['twig'] = empty($matches[0]) ? [] : array_values(array_unique($matches[0]));
                    }
                }
                break;
            } elseif ($first === '@') {
                // Use the same regex than application/asset/js/admin.js.
                // @link http://stackoverflow.com/questions/7035825/regular-expression-for-a-language-tag-as-defined-by-bcp47
                if (preg_match('/^(?<language>((en-GB-oed|i-ami|i-bnn|i-default|i-enochian|i-hak|i-klingon|i-lux|i-mingo|i-navajo|i-pwn|i-tao|i-tay|i-tsu|sgn-BE-FR|sgn-BE-NL|sgn-CH-DE)|(art-lojban|cel-gaulish|no-bok|no-nyn|zh-guoyu|zh-hakka|zh-min|zh-min-nan|zh-xiang))|((([A-Za-z]{2,3}(-([A-Za-z]{3}(-[A-Za-z]{3}){0,2}))?))(-([A-Za-z]{4}))?(-([A-Za-z]{2}|[0-9]{3}))?(-([A-Za-z0-9]{5,8}|[0-9][A-Za-z0-9]{3}))*(-([0-9A-WY-Za-wy-z](-[A-Za-z0-9]{2,8})+))*(-(x(-[A-Za-z0-9]{1,8})+))?)|(x(-[A-Za-z0-9]{1,8})+))$/', trim(mb_substr($part, 1)), $matches)) {
                    $base['@language'] = $matches['language'];
                }
            } elseif ($first === '§') {
                $second = trim(mb_substr($part, 1));
                if ($second === 'public') {
                    $base['is_public'] = true;
                } elseif ($second === 'private') {
                    $base['is_public'] = false;
                }
            } elseif (mb_substr($part, 0, 2) === '^^') {
                if (preg_match('~^(?<datatype>(?:[a-zA-Z][\w;]*:[\w\p{L}][\w\p{L}:;\s-]*?|[a-zA-Z][\w;\s-]*)+)~', trim(mb_substr($part, 2)), $matches)) {
                    $base['type'] = $matches['datatype'];
                }
            } elseif (in_array($part, ['{__label__}', '{list}'])) {
                $base = [];
                $base['field'] = $part;
                $base['pattern'] = $part;
                $base['replace'] = [$part];
                break;
            } elseif (preg_match('~^(?<field>[a-zA-Z0-9-_]+:[a-zA-Z0-9-_]+)$~', $part, $matches)) {
                $base['field'] = $matches['field'];
            }
        }
        return $base['field']
            ? $base
            : null;
    }

    /**
     * Trim all whitespaces.
     */
    public function trimUnicode($string): string
    {
        return preg_replace('/^\s+|\s+$/u', '', (string) $string);
    }
}

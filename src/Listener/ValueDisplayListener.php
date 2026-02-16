<?php declare(strict_types=1);

namespace AdvancedResourceTemplate\Listener;

use Interop\Container\ContainerInterface;
use Laminas\EventManager\Event;
use Omeka\Mvc\Status;
use Omeka\Settings\Settings;

/**
 * Listener to handle display of property values with search links and icons.
 *
 * Handles two events:
 * - rep.value.html: Full display with prepend/append icons and value links
 * - view.show.value: Simplified display with append icons only
 */
class ValueDisplayListener
{
    /**
     * @var \Omeka\Mvc\Status
     */
    protected $status;

    /**
     * @var \Omeka\Settings\Settings
     */
    protected $settings;

    /**
     * @var \Omeka\Settings\SiteSettings
     */
    protected $siteSettings;

    /**
     * @var \Laminas\View\HelperPluginManager
     */
    protected $viewHelpers;

    /**
     * Used for lazy-loading of site settings when the factory is called
     * during bootstrap, before routing is complete. Required for modules
     * like CleanUrl that forward routes after the initial route match.
     *
     * @var \Interop\Container\ContainerInterface
     */
    protected $services;

    /**
     * Context initialized flag per mode.
     */
    protected $initialized = [
        'representation' => false,
        'record' => false,
    ];

    /**
     * Display is disabled flag per mode.
     */
    protected $disabled = [
        'representation' => false,
        'record' => false,
    ];

    /**
     * Context data per mode.
     */
    protected $context = [];

    public function __construct(
        Status $status,
        Settings $settings,
        $siteSettings,
        $viewHelpers,
        ContainerInterface $services = null
    ) {
        $this->status = $status;
        $this->settings = $settings;
        $this->siteSettings = $siteSettings;
        $this->viewHelpers = $viewHelpers;
        $this->services = $services;
    }

    /**
     * Handle rep.value.html event.
     */
    public function handleRepresentationValueHtml(Event $event): void
    {
        if (!$this->initContext('representation')) {
            return;
        }

        $value = $event->getTarget();
        $property = $value->property()->term();

        if (!$this->isPropertyAllowed('representation', $property)) {
            return;
        }

        // When the value is attached to a value annotation, there may be no resource.
        try {
            $resource = $value->resource();
            $controllerName = $resource ? $resource->getControllerName() : null;
        } catch (\Exception $e) {
            $resource = null;
            $controllerName = null;
        }
        if (!$controllerName) {
            return;
        }

        $display = $this->context['representation']['display'];
        $html = $event->getParam('html');
        $htmlClean = html_entity_decode($html, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5);

        $vr = $value->valueResource();
        $uri = $value->uri();
        $val = (string) $value->value();
        $vrId = $vr ? $vr->id() : null;
        $uriOrVal = $uri ?: $val;

        $result = [
            'prepend_icon_search' => '',
            'prepend_icon_advanced_search' => '',
            'prepend_icon_resource' => '',
            'prepend_icon_uri' => '',
            'value_default' => '',
            'value_search' => '',
            'value_advanced_search' => '',
            'append_icon_search' => '',
            'append_icon_advanced_search' => '',
            'append_icon_resource' => '',
            'append_icon_uri' => '',
        ];

        if ($display['default']) {
            if ($vr && $display['value_text_resource']) {
                $result['value_default'] = strip_tags($htmlClean);
            } elseif ($uri && $display['value_text_uri']) {
                $result['value_default'] = strip_tags($htmlClean);
            } else {
                $result['value_default'] = $html;
            }
        }

        if ($display['search']) {
            $searchUrl = $this->buildSearchUrl('representation', $property, $controllerName, $vrId, $uriOrVal);
            if ($display['value_search']) {
                $searchLabel = $vr ? $htmlClean : (strlen($val) ? $val : $uri);
                $result['value_search'] = $this->buildValueSearchLink('representation', $searchLabel, $searchUrl);
            }
            if ($display['icon_search']) {
                $htmlIconSearch = $this->buildSearchIcon('representation', $searchUrl);
                $result['prepend_icon_search'] = $display['prepend_icon_search'] ? $htmlIconSearch : '';
                $result['append_icon_search'] = $display['append_icon_search'] ? $htmlIconSearch : '';
            }
        }

        if ($display['advanced_search']) {
            $searchUrl = $this->buildAdvancedSearchUrl('representation', $property, $vrId, $uriOrVal);
            if ($searchUrl) {
                if ($display['value_advanced_search']) {
                    $searchLabel = $vr ? $htmlClean : (strlen($val) ? $val : $uri);
                    $result['value_advanced_search'] = $this->buildValueSearchLink('representation', $searchLabel, $searchUrl);
                }
                if ($display['icon_advanced_search']) {
                    $htmlIconSearch = $this->buildSearchIcon('representation', $searchUrl);
                    $result['prepend_icon_advanced_search'] = $display['prepend_icon_advanced_search'] ? $htmlIconSearch : '';
                    $result['append_icon_advanced_search'] = $display['append_icon_advanced_search'] ? $htmlIconSearch : '';
                }
            }
        }

        if ($display['icon_resource'] && $vr) {
            $htmlIconResource = $this->buildResourceIcon('representation', $vr);
            $result['prepend_icon_resource'] = $display['prepend_icon_resource'] ? $htmlIconResource : '';
            $result['append_icon_resource'] = $display['append_icon_resource'] ? $htmlIconResource : '';
        }

        if ($display['icon_uri'] && $uri) {
            $htmlIconUri = $this->buildUriIcon('representation', $uri);
            $result['prepend_icon_uri'] = $display['prepend_icon_uri'] ? $htmlIconUri : '';
            $result['append_icon_uri'] = $display['append_icon_uri'] ? $htmlIconUri : '';
        }

        $event->setParam('html', implode(' ', array_filter($result, 'strlen')));
    }

    /**
     * Handle view.show.value event.
     */
    public function handleViewResourceShowValue(Event $event): void
    {
        if (!$this->initContext('record')) {
            return;
        }

        $value = $event->getParam('value');
        $property = $value->property()->term();

        if (!$this->isPropertyAllowed('record', $property)) {
            return;
        }

        // When the value is attached to a value annotation, there may be no resource.
        try {
            $resource = $value->resource();
            $controllerName = $resource ? $resource->getControllerName() : null;
        } catch (\Exception $e) {
            $resource = null;
            $controllerName = null;
        }
        if (!$controllerName) {
            return;
        }

        $display = $this->context['record']['display'];

        $vr = $value->valueResource();
        $uri = $value->uri();
        $val = (string) $value->value();
        $vrId = $vr ? $vr->id() : null;
        $uriOrVal = $uri ?: $val;

        $result = [
            'record_append_icon_search' => '',
            'record_append_icon_advanced_search' => '',
            'record_append_icon_resource' => '',
            'record_append_icon_uri' => '',
        ];

        if ($display['search']) {
            $searchUrl = $this->buildSearchUrl('record', $property, $controllerName, $vrId, $uriOrVal);
            if ($display['icon_search']) {
                $htmlIconSearch = $this->buildSearchIcon('record', $searchUrl);
                $result['record_append_icon_search'] = $display['record_append_icon_search'] ? $htmlIconSearch : '';
            }
        }

        if ($display['advanced_search']) {
            $searchUrl = $this->buildAdvancedSearchUrl('record', $property, $vrId, $uriOrVal);
            if ($searchUrl && $display['icon_advanced_search']) {
                $htmlIconSearch = $this->buildSearchIcon('record', $searchUrl);
                $result['record_append_icon_advanced_search'] = $display['record_append_icon_advanced_search'] ? $htmlIconSearch : '';
            }
        }

        if ($display['icon_resource'] && $vr) {
            $htmlIconResource = $this->buildResourceIcon('record', $vr);
            $result['record_append_icon_resource'] = $display['record_append_icon_resource'] ? $htmlIconResource : '';
        }

        if ($display['icon_uri'] && $uri) {
            $htmlIconUri = $this->buildUriIcon('record', $uri);
            $result['record_append_icon_uri'] = $display['record_append_icon_uri'] ? $htmlIconUri : '';
        }

        echo implode(' ', array_filter($result, 'strlen'));
    }

    /**
     * Initialize context for a given mode.
     */
    protected function initContext(string $mode): bool
    {
        if ($this->initialized[$mode]) {
            return !$this->disabled[$mode];
        }

        $this->initialized[$mode] = true;

        $isSite = $this->status->isSiteRequest();
        $isAdmin = $this->status->isAdminRequest();

        // Warning: some background jobs may need to get full html.
        if (!$isSite && !$isAdmin) {
            $this->disabled[$mode] = true;
            return false;
        }

        if ($isSite) {
            // Lazy-load site settings: the factory may have been created before
            // routing (e.g., during bootstrap), when isSiteRequest() was false.
            // After route forwarding (e.g., CleanUrl), site context is available.
            if (!$this->siteSettings && $this->services) {
                try {
                    $this->siteSettings = $this->services->get('Omeka\Settings\Site');
                } catch (\Exception $e) {
                    $this->disabled[$mode] = true;
                    return false;
                }
            }
            if (!$this->siteSettings) {
                $this->disabled[$mode] = true;
                return false;
            }
            $displaySite = $this->siteSettings->get('advancedresourcetemplate_properties_display_site');
            if ($displaySite === 'site') {
                $sSettings = $this->siteSettings;
            } elseif ($displaySite === 'main') {
                $sSettings = $this->settings;
            } else {
                $this->disabled[$mode] = true;
                return false;
            }
        } elseif (!$this->settings->get('advancedresourcetemplate_properties_display_admin')) {
            $this->disabled[$mode] = true;
            return false;
        } else {
            // Admin.
            $sSettings = $this->settings;
        }

        // Get allowed display options based on mode.
        $allowed = $mode === 'representation'
            ? $this->getAllowedRepresentation()
            : $this->getAllowedRecord();

        // A specific part should be configured to be displayed.
        $display = (array) $sSettings->get('advancedresourcetemplate_properties_display', []);
        $display = array_values(array_intersect($allowed, $display));
        if (!$display) {
            $this->disabled[$mode] = true;
            return false;
        }

        // White list should contain at least one value ("all" or specific properties).
        $whitelist = $sSettings->get('advancedresourcetemplate_properties_as_search_whitelist', []);
        if (!$whitelist) {
            $this->disabled[$mode] = true;
            return false;
        }

        $blacklist = $sSettings->get('advancedresourcetemplate_properties_as_search_blacklist', []);
        $whitelistAll = in_array('all', $whitelist);

        $url = $this->viewHelpers->get('url');
        $escape = $this->viewHelpers->get('escapeHtml');
        $translate = $this->viewHelpers->get('translate');
        $escapeAttr = $this->viewHelpers->get('escapeHtmlAttr');
        $hyperlink = $this->viewHelpers->has('hyperlink') ? $this->viewHelpers->get('hyperlink') : null;
        $advancedSearchConfigHelper = $this->viewHelpers->has('getSearchConfig') ? $this->viewHelpers->get('getSearchConfig') : null;
        $siteSlug = $isSite ? $this->status->getRouteParam('site-slug') : null;

        $display = array_replace(array_fill_keys($allowed, false), array_fill_keys($display, true));

        // Prepare merged display keys.
        $display = $this->prepareDisplayKeys($mode, $display, $isAdmin, $advancedSearchConfigHelper);

        // Prepare translated texts.
        $text = [
            'search' => $escape($translate('Search this value')), // @translate
            'item' => $escape($translate('Show this item')), // @translate
            'media' => $escape($translate('Show this media')), // @translate
            'item-set' => $escape($translate('Show this item set')), // @translate
            'resource' => $escape($translate('Show this resource')), // @translate
            'uri' => $escape($translate('Open this external uri in a new tab')), // @translate
        ];

        // Store context.
        $this->context[$mode] = [
            'isAdmin' => $isAdmin,
            'isSite' => $isSite,
            'display' => $display,
            'whitelist' => $whitelist,
            'blacklist' => $blacklist,
            'whitelistAll' => $whitelistAll,
            'url' => $url,
            'hyperlink' => $hyperlink,
            'escapeAttr' => $escapeAttr,
            'siteSlug' => $siteSlug,
            'text' => $text,
            'advancedSearchConfig' => $display['advanced_search']
                ? ($advancedSearchConfigHelper ? $advancedSearchConfigHelper() : null)
                : null,
            'isInternalQuerier' => false,
        ];

        // Check advanced search querier.
        if ($this->context[$mode]['advancedSearchConfig']) {
            $searchEngine = $this->context[$mode]['advancedSearchConfig']->searchEngine();
            $querier = $searchEngine ? $searchEngine->querier() : null;
            $this->context[$mode]['isInternalQuerier'] = $querier instanceof \AdvancedSearch\Querier\InternalQuerier;
        }

        return true;
    }

    /**
     * Check if a property is allowed for display.
     */
    protected function isPropertyAllowed(string $mode, string $property): bool
    {
        $ctx = $this->context[$mode] ?? null;
        if (!$ctx) {
            return false;
        }

        if ($ctx['whitelistAll']) {
            return !in_array($property, $ctx['blacklist']);
        }

        return in_array($property, $ctx['whitelist']);
    }

    /**
     * Build standard search URL.
     */
    protected function buildSearchUrl(
        string $mode,
        string $property,
        string $controllerName,
        ?int $valueResourceId,
        ?string $uriOrVal
    ): string {
        $ctx = $this->context[$mode];

        if ($valueResourceId) {
            $query = [
                'property[0][property]' => $property,
                'property[0][type]' => 'res',
                'property[0][text]' => $valueResourceId,
            ];
        } else {
            $query = [
                'property[0][property]' => $property,
                'property[0][type]' => 'eq',
                'property[0][text]' => $uriOrVal,
            ];
        }

        return $ctx['url'](
            $ctx['isAdmin'] ? 'admin/default' : 'site/resource',
            ['site-slug' => $ctx['siteSlug'], 'controller' => $controllerName, 'action' => 'browse'],
            ['query' => $query]
        );
    }

    /**
     * Build advanced search URL.
     */
    protected function buildAdvancedSearchUrl(
        string $mode,
        string $property,
        ?int $valueResourceId,
        ?string $uriOrVal
    ): ?string {
        $ctx = $this->context[$mode];

        if (!$ctx['advancedSearchConfig']) {
            return null;
        }

        if ($ctx['isInternalQuerier']) {
            $urlQuery = [
                'filter' => [[
                    'field' => $property,
                    'type' => $valueResourceId ? 'res' : 'eq',
                    'val' => $valueResourceId ?: $uriOrVal,
                ]],
            ];
        } else {
            $prop = is_array($property) && !$ctx['advancedSearchConfig']
                ? reset($property)
                : $property;
            $urlQuery = [
                'filter' => [[
                    'field' => $prop,
                    'type' => $valueResourceId ? 'res' : 'eq',
                    'val' => $valueResourceId ?: $uriOrVal,
                ]],
            ];
        }

        return $ctx['isAdmin']
            ? $ctx['advancedSearchConfig']->adminSearchUrl(false, $urlQuery)
            : $ctx['advancedSearchConfig']->siteUrl($ctx['siteSlug'], false, $urlQuery);
    }

    /**
     * Build search icon HTML.
     */
    protected function buildSearchIcon(string $mode, string $searchUrl): string
    {
        $ctx = $this->context[$mode];
        return sprintf(
            '<a href="%1$s" class="metadata-search-link"><span title="%2$s" class="o-icon-search"></span></a>',
            $ctx['escapeAttr']($searchUrl),
            $ctx['text']['search']
        );
    }

    /**
     * Build search link on value text.
     */
    protected function buildValueSearchLink(string $mode, string $label, string $searchUrl): string
    {
        $ctx = $this->context[$mode];
        if (!$ctx['hyperlink']) {
            return '';
        }
        return $ctx['hyperlink'](strip_tags($label), $searchUrl, ['class' => 'metadata-search-link']);
    }

    /**
     * Build resource icon HTML.
     */
    protected function buildResourceIcon(string $mode, $valueResource): string
    {
        $ctx = $this->context[$mode];

        $vrType = $valueResource->getControllerName() ?? 'resource';
        $vrName = $valueResource->resourceName() ?? 'resources';
        $vrUrl = $ctx['isAdmin'] ? $valueResource->adminUrl() : $valueResource->siteUrl($ctx['siteSlug']);

        return $ctx['isAdmin']
            ? sprintf(
                '<a href="%1$s" class="resource-link"><span title="%2$s" class="resource-name"></a>',
                $ctx['escapeAttr']($vrUrl),
                $ctx['text'][$vrType]
            )
            : sprintf(
                '<a href="%1$s" class="resource-link"><span title="%2$s" class="o-icon-%3$s resource-name"></span></a>',
                $ctx['escapeAttr']($vrUrl),
                $ctx['text'][$vrType],
                $vrName
            );
    }

    /**
     * Build URI icon HTML.
     */
    protected function buildUriIcon(string $mode, string $uri): string
    {
        $ctx = $this->context[$mode];

        return $ctx['isAdmin']
            ? sprintf(
                '<a href="%1$s" class="uri-value-link" target="_blank" rel="noopener" title="%2$s"></a>',
                $ctx['escapeAttr']($uri),
                $ctx['text']['uri']
            )
            : sprintf(
                '<a href="%1$s" class="uri-value-link" target="_blank" rel="noopener"><span title="%2$s" class="o-icon-external"></span></a>',
                $ctx['escapeAttr']($uri),
                $ctx['text']['uri']
            );
    }

    /**
     * Get allowed options for representation mode.
     */
    protected function getAllowedRepresentation(): array
    {
        return [
            'prepend_icon_search',
            'prepend_icon_advanced_search',
            'prepend_icon_resource',
            'prepend_icon_uri',
            'value_search',
            'value_advanced_search',
            'value_text_resource',
            'value_text_uri',
            'append_icon_search',
            'append_icon_advanced_search',
            'append_icon_resource',
            'append_icon_uri',
        ];
    }

    /**
     * Get allowed options for record mode.
     */
    protected function getAllowedRecord(): array
    {
        return [
            'record_append_icon_search',
            'record_append_icon_advanced_search',
            'record_append_icon_resource',
            'record_append_icon_uri',
        ];
    }

    /**
     * Prepare merged display keys based on mode and settings.
     */
    protected function prepareDisplayKeys(string $mode, array $display, bool $isAdmin, $advancedSearchConfigHelper): array
    {
        if ($mode === 'representation') {
            return $this->prepareDisplayKeysRepresentation($display, $isAdmin, $advancedSearchConfigHelper);
        }

        return $this->prepareDisplayKeysRecord($display, $isAdmin, $advancedSearchConfigHelper);
    }

    /**
     * Prepare display keys for representation mode.
     */
    protected function prepareDisplayKeysRepresentation(array $display, bool $isAdmin, $advancedSearchConfigHelper): array
    {
        $display['icon_search'] = $display['prepend_icon_search'] || $display['append_icon_search'];
        $display['icon_resource'] = $display['prepend_icon_resource'] || $display['append_icon_resource'];
        $display['icon_uri'] = $display['prepend_icon_uri'] || $display['append_icon_uri'];
        $display['search'] = $display['value_search'] || $display['icon_search'];
        $display['default'] = !$display['value_search'] && !$display['value_advanced_search'];
        $display['advanced_search'] = false;

        if ($advancedSearchConfigHelper) {
            $display['icon_advanced_search'] = $display['prepend_icon_advanced_search'] || $display['append_icon_advanced_search'];
            $display['advanced_search'] = $display['value_advanced_search'] || $display['icon_advanced_search'];
            $advancedSearchConfig = $display['advanced_search'] ? $advancedSearchConfigHelper() : null;
            $searchEngine = $advancedSearchConfig ? $advancedSearchConfig->searchEngine() : null;
            $querier = $searchEngine ? $searchEngine->querier() : null;

            if ($display['advanced_search'] && (!$querier || $querier instanceof \AdvancedSearch\Querier\NoopQuerier)) {
                $display['value_search'] = $display['value_search'] || $display['value_advanced_search'];
                $display['prepend_icon_search'] = $display['prepend_icon_search'] || $display['prepend_icon_advanced_search'];
                $display['append_icon_search'] = $display['append_icon_search'] || $display['append_icon_advanced_search'];
                $display['icon_search'] = $display['prepend_icon_search'] || $display['append_icon_search'];
                $display['search'] = $display['value_search'] || $display['icon_search'];
                $display['default'] = !$display['value_search'];
                $display['value_advanced_search'] = false;
                $display['prepend_icon_advanced_search'] = false;
                $display['append_icon_advanced_search'] = false;
                $display['icon_advanced_search'] = false;
                $display['advanced_search'] = false;
            }
        } else {
            $display['value_search'] = $display['value_search'] || $display['value_advanced_search'];
            $display['prepend_icon_search'] = $display['prepend_icon_search'] || $display['prepend_icon_advanced_search'];
            $display['append_icon_search'] = $display['append_icon_search'] || $display['append_icon_advanced_search'];
            $display['icon_search'] = $display['prepend_icon_search'] || $display['append_icon_search'];
            $display['search'] = $display['value_search'] || $display['icon_search'];
            $display['default'] = !$display['value_search'];
        }

        if ($isAdmin && $display['default']) {
            if ($display['append_icon_resource']) {
                $display['append_icon_resource'] = false;
                $display['icon_resource'] = $display['prepend_icon_resource'];
            }
            if ($display['append_icon_uri']) {
                $display['append_icon_uri'] = false;
                $display['icon_uri'] = $display['prepend_icon_uri'];
            }
        }

        return $display;
    }

    /**
     * Prepare display keys for record mode.
     */
    protected function prepareDisplayKeysRecord(array $display, bool $isAdmin, $advancedSearchConfigHelper): array
    {
        $display['icon_search'] = $display['record_append_icon_search'];
        $display['icon_resource'] = $display['record_append_icon_resource'];
        $display['icon_uri'] = $display['record_append_icon_uri'];
        $display['search'] = $display['icon_search'];
        $display['advanced_search'] = false;

        if ($advancedSearchConfigHelper) {
            $display['icon_advanced_search'] = $display['record_append_icon_advanced_search'];
            $display['advanced_search'] = $display['icon_advanced_search'];
            $advancedSearchConfig = $display['advanced_search'] ? $advancedSearchConfigHelper() : null;
            $searchEngine = $advancedSearchConfig ? $advancedSearchConfig->searchEngine() : null;
            $querier = $searchEngine ? $searchEngine->querier() : null;

            if ($display['advanced_search'] && (!$querier || $querier instanceof \AdvancedSearch\Querier\NoopQuerier)) {
                $display['record_append_icon_search'] = $display['record_append_icon_advanced_search'] || $display['record_append_icon_advanced_search'];
                $display['record_append_icon_advanced_search'] = false;
                $display['icon_search'] = $display['record_append_icon_search'];
                $display['icon_advanced_search'] = false;
                $display['search'] = $display['record_append_icon_search'];
                $display['advanced_search'] = false;
            }
        } else {
            $display['record_append_icon_search'] = $display['record_append_icon_advanced_search'] || $display['record_append_icon_advanced_search'];
            $display['record_append_icon_advanced_search'] = false;
            $display['icon_search'] = $display['record_append_icon_search'];
            $display['icon_advanced_search'] = false;
            $display['search'] = $display['record_append_icon_search'];
            $display['advanced_search'] = false;
        }

        if ($isAdmin) {
            if ($display['record_append_icon_resource']) {
                $display['record_append_icon_resource'] = false;
                $display['icon_resource'] = false;
            }
            if ($display['record_append_icon_uri']) {
                $display['record_append_icon_uri'] = false;
                $display['icon_uri'] = false;
            }
        }

        return $display;
    }
}

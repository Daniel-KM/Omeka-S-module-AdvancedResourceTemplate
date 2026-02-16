<?php declare(strict_types=1);

namespace AdvancedResourceTemplate\Service\Listener;

use AdvancedResourceTemplate\Listener\ValueDisplayListener;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ValueDisplayListenerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $status = $services->get('Omeka\Status');

        // SiteSettings may not be available (e.g., in background jobs or
        // admin without site context).
        // This factory is called during bootstrap (via attachListeners),
        // before routing. For modules like CleanUrl that forward routes,
        // isSiteRequest() may return false here and become true later.
        // The container is passed to allow lazy-loading of site settings.
        $siteSettings = null;
        if ($status->isSiteRequest()) {
            $siteSettings = $services->get('Omeka\Settings\Site');
        }

        return new ValueDisplayListener(
            $status,
            $services->get('Omeka\Settings'),
            $siteSettings,
            $services->get('ViewHelperManager'),
            $services
        );
    }
}

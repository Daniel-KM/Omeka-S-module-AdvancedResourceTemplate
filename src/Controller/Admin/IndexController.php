<?php declare(strict_types=1);

namespace AdvancedResourceTemplate\Controller\Admin;

use AdvancedResourceTemplate\Autofiller\AbstractAutofiller;
use Common\Mvc\Controller\Plugin\JSend;
use Common\Stdlib\PsrMessage;
use Doctrine\ORM\EntityManager;
use Laminas\Http\Response as HttpResponse;
use Laminas\I18n\Translator\TranslatorInterface;
use Laminas\Mvc\Controller\AbstractRestfulController;
use Laminas\View\Model\JsonModel;
use Omeka\Api\Exception\NotFoundException;
use Omeka\Mvc\Exception\NotFoundException as MvcNotFoundException;

class IndexController extends AbstractRestfulController
{
    /**
     * @var EntityManager
     */
    protected $entityManager;

    /**
     * @var TranslatorInterface
     */
    protected $translator;

    /**
     * Autofiller classes by service name.
     *
     * @var array
     */
    protected $autofillers;

    /**
     * @var callable|null
     */
    protected $autofillerFactory;

    public function __construct(
        EntityManager $entityManager,
        TranslatorInterface $translator,
        array $autofillers = [],
        ?callable $autofillerFactory = null
    ) {
        $this->entityManager = $entityManager;
        $this->translator = $translator;
        $this->autofillers = $autofillers;
        $this->autofillerFactory = $autofillerFactory;
    }

    public function valuesAction()
    {
        $maxResults = 10;

        $query = $this->params()->fromQuery();
        $q = isset($query['q']) ? trim($query['q']) : '';
        if (!strlen($q)) {
            return $this->jSend(JSend::FAIL, [
                'suggestions' =>(new PsrMessage('The query is empty.'))->setTranslator($this->translator), // @translate
            ]);
        }

        $qq = isset($query['type']) && $query['type'] === 'in'
             ? '%' . addcslashes($q, '%_') . '%'
             : addcslashes($q, '%_') . '%';

        $property = isset($query['prop']) ? (int) $query['prop'] : null;

        $qb = $this->entityManager->createQueryBuilder();
        $expr = $qb->expr();
        $qb
            ->select('DISTINCT omeka_root.value')
            ->from(\Omeka\Entity\Value::class, 'omeka_root')
            ->where($expr->like('omeka_root.value', ':qq'))
            ->setParameter('qq', $qq)
            ->groupBy('omeka_root.value')
            ->orderBy('omeka_root.value', 'ASC')
            ->setMaxResults($maxResults);
        if ($property) {
            $qb
                ->andWhere($expr->eq('omeka_root.property', ':prop'))
                ->setParameter('prop', $property);
        }
        $result = $qb->getQuery()->getScalarResult();

        // Output for jSend + jQuery Autocomplete.
        // @link https://github.com/omniti-labs/jsend
        // @link https://www.devbridge.com/sourcery/components/jquery-autocomplete
        $result = array_map('trim', array_column($result, 'value'));
        return $this->jSend(JSend::SUCCESS, [
            'suggestions' => $result,
        ]);
    }

    public function autofillerAction()
    {
        $q = trim((string) $this->params()->fromQuery('q'));
        if (!strlen($q)) {
            return $this->jSend(JSend::FAIL, [
                'suggestions' => (new PsrMessage('The query is empty.'))->setTranslator($this->translator), // @translate
            ]);
        }

        $autofiller = $this->requestToAutofiller();
        if ($autofiller instanceof JsonModel) {
            return $autofiller;
        }

        $lang = $this->userSettings()->get('locale')
            ?: ($this->settings()->get('locale') ?: null);

        $results = $autofiller->getResults($q, $lang);
        if ($results === null) {
            return $this->jSend(JSend::ERROR, null, (new PsrMessage(
                'The remote service "{service}" seems unavailable.', // @translate
                ['service' => $this->translate($autofiller->getLabel())]
            ))->setTranslator($this->translator)->translate(), HttpResponse::STATUS_CODE_502);
        }

        return $this->jSend(JSend::SUCCESS, [
            'suggestions' => $results,
        ]);
    }

    public function autofillerSettingsAction()
    {
        if (!$this->getRequest()->isXmlHttpRequest()) {
            throw new MvcNotFoundException();
        }

        $autofiller = $this->requestToAutofiller();
        if ($autofiller instanceof JsonModel) {
            return $autofiller;
        }

        return $this->jSend(JSend::SUCCESS, [
            'autofiller' => [
                'label' => $this->translate($autofiller->getLabel()),
            ],
        ]);
    }

    /**
     * Get the autofiller set in the template for the requested service.
     *
     * Each line of the template setting "autofillers" is a service with an
     * optional sub-service and an optional mapping reference of module Mapper:
     * "idref:person", "geonames = mapping:5", "generic:json #api = user:x.ini".
     *
     * @return AbstractAutofiller|JsonModel
     */
    protected function requestToAutofiller()
    {
        if (!$this->autofillerFactory) {
            return $this->jSend(JSend::ERROR, null, (new PsrMessage(
                'The autofillers require the module Mapper.' // @translate
            ))->setTranslator($this->translator)->translate(), HttpResponse::STATUS_CODE_501);
        }

        $service = trim((string) $this->params()->fromQuery('service'));
        if (!strlen($service)) {
            return $this->jSend(JSend::FAIL, [
                'suggestions' => (new PsrMessage('The service is empty.'))->setTranslator($this->translator), // @translate
            ]);
        }

        $templateId = (int) $this->params()->fromQuery('template');
        if (!$templateId) {
            return $this->jSend(JSend::FAIL, [
                'suggestions' => (new PsrMessage('The template is empty.'))->setTranslator($this->translator), // @translate
            ]);
        }

        try {
            /** @var \AdvancedResourceTemplate\Api\Representation\ResourceTemplateRepresentation $template */
            $template = $this->api()->read('resource_templates', ['id' => $templateId])->getContent();
        } catch (NotFoundException $e) {
            return $this->jSend(JSend::FAIL, [
                'suggestions' => (new PsrMessage(
                    'The template "{template_id}" is not available.', // @translate
                    ['template_id' => $templateId]
                ))->setTranslator($this->translator)->translate(),
            ]);
        }

        $options = null;
        foreach ((array) $template->dataValue('autofillers') as $line) {
            [$key, $mapping] = array_map('trim', explode('=', (string) $line, 2)) + [1 => ''];
            if ($key === $service) {
                // The optional variant ("#xxx") allows to use a service twice.
                [$name, $sub] = array_map('trim', explode(':', strtok($key, '#'), 2)) + [1 => ''];
                $options = [
                    'key' => $key,
                    'service' => $name,
                    'sub' => $sub,
                    'mapping' => $mapping,
                ];
                break;
            }
        }

        if (!$options || empty($this->autofillers[$options['service']])) {
            return $this->jSend(JSend::ERROR, null, (new PsrMessage(
                'The service "{service}" is not available.', // @translate
                ['service' => $service]
            ))->setTranslator($this->translator)->translate(), HttpResponse::STATUS_CODE_501);
        }

        // A template may be edited by non-admin users, so the mapping must be a
        // reference, never a content that may query any url from the server.
        if ($options['mapping'] !== '' && !preg_match('~^(?:mapping|module|user):\S~', $options['mapping'])) {
            return $this->jSend(JSend::ERROR, null, (new PsrMessage(
                'The mapping of the autofiller "{service}" must be a reference to a mapping of module Mapper ("mapping:", "module:" or "user:").', // @translate
                ['service' => $service]
            ))->setTranslator($this->translator)->translate(), HttpResponse::STATUS_CODE_400);
        }

        return ($this->autofillerFactory)($this->autofillers[$options['service']], $options);
    }

    /**
     * Check if the request contains an identifier.
     *
     * This method overrides parent in order to allow to query on one or
     * multiple ids.
     *
     * @see \Omeka\Controller\ApiController::getIdentifier()
     *
     * {@inheritDoc}
     * @see \Laminas\Mvc\Controller\AbstractRestfulController::getIdentifier()
     */
    protected function getIdentifier($routeMatch, $request)
    {
        $identifier = $this->getIdentifierName();
        return $routeMatch->getParam($identifier, false);
    }
}

<?php declare(strict_types=1);

namespace AdvancedResourceTemplate\Controller\Admin;

use Common\Mvc\Controller\Plugin\JSend;
use Common\Stdlib\PsrMessage;
use Doctrine\ORM\EntityManager;
use Laminas\Mvc\Controller\AbstractRestfulController;

class IndexController extends AbstractRestfulController
{
    /**
     * @var EntityManager
     */
    protected $entityManager;

    /**
     * @param EntityManager $entityManager
     */
    public function __construct(
        EntityManager $entityManager
    ) {
        $this->entityManager = $entityManager;
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

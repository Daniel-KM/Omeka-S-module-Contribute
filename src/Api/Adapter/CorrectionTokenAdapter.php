<?php
namespace Correction\Api\Adapter;

use Doctrine\Common\Inflector\Inflector;
use Doctrine\ORM\QueryBuilder;
use Omeka\Api\Adapter\AbstractEntityAdapter;
use Omeka\Api\Request;
use Omeka\Entity\EntityInterface;
use Omeka\Stdlib\ErrorStore;

class CorrectionTokenAdapter extends AbstractEntityAdapter
{
    protected $sortFields = [
        'id' => 'id',
        'resource' => 'resource',
        'email' => 'email',
        'expire' => 'expire',
        'created' => 'created',
        'accessed' => 'accessed',
    ];

    public function getResourceName()
    {
        return 'correction_tokens';
    }

    public function getRepresentationClass()
    {
        return \Correction\Api\Representation\CorrectionTokenRepresentation::class;
    }

    public function getEntityClass()
    {
        return \Correction\Entity\CorrectionToken::class;
    }

    public function hydrate(Request $request, EntityInterface $entity, ErrorStore $errorStore)
    {
        // TODO Improve hydration (don't update token, created, etc.).
        /** @var \Correction\Entity\CorrectionToken $entity */
        $data = $request->getContent();
        foreach ($data as $key => $value) {
            $method = 'set' . Inflector::classify($key);
            switch ($method) {
                case 'resource_id':
                    $resource = $this->getAdapter('resources')->findEntity($data['resource_id']);
                    $entity->setResource($resource);
                    break;
                case 'token':
                    if (empty($value)) {
                        $value = $this->createToken();
                    }
                    break;
                case method_exists($entity, $method):
                    $entity->$method($value);
                    break;
            }
        }
    }

    public function buildQuery(QueryBuilder $qb, array $query)
    {
        if (isset($query['id'])) {
            $qb->andWhere($qb->expr()->eq(
                $this->getEntityClass() . '.id',
                $this->createNamedParameter($qb, $query['id']))
            );
        }

        if (isset($query['resource_id'])) {
            if (!is_array($query['resource_id'])) {
                $query['resource_id'] = [$query['resource_id']];
            }
            $resourceAlias = $this->createAlias();
            $qb->innerJoin(
                $this->getEntityClass() . '.resource',
                $resourceAlias
            );
            $qb->andWhere($qb->expr()->in(
                $resourceAlias . '.id',
                $this->createNamedParameter($qb, $query['resource_id']))
            );
        }

        if (isset($query['token'])) {
            $qb->andWhere($qb->expr()->eq(
                $this->getEntityClass() . '.token',
                $this->createNamedParameter($qb, $query['token']))
            );
        }

        if (isset($query['email'])) {
            $qb->andWhere($qb->expr()->eq(
                $this->getEntityClass() . '.email',
                $this->createNamedParameter($qb, $query['email']))
            );
        }

        // TODO Add time comparison (see modules AdvancedSearchPlus or Next).
        if (isset($query['expire'])) {
            $qb->andWhere($qb->expr()->eq(
                $this->getEntityClass() . '.expire',
                $this->createNamedParameter($qb, $query['expire']))
            );
        }

        if (isset($query['created'])) {
            $qb->andWhere($qb->expr()->eq(
                $this->getEntityClass() . '.created',
                $this->createNamedParameter($qb, $query['created']))
            );
        }

        if (isset($query['accessed'])) {
            $qb->andWhere($qb->expr()->eq(
                $this->getEntityClass() . '.accessed',
                $this->createNamedParameter($qb, $query['accessed']))
            );
        }
    }

    protected function createToken()
    {
        $entityManager = $this->getEntityManager();
        $repository = $entityManager->getRepository($this->getEntityClass());

        if (PHP_VERSION_ID < 70000) {
            $tokenString = function() { return sha1(mt_rand()); };
        } else {
            $tokenString = function() { return substr(str_replace(['+', '/', '-', '='], '', base64_encode(random_bytes(16))), 0, 10); };
        }

        // Check if the token is unique.
        $token = $tokenString();
        while (true) {
            $result = $repository->findOneBy(['token' => $token]);
            if (!$result) {
                break;
            }
            $token = $tokenString();
        }

        return $token;
    }
}

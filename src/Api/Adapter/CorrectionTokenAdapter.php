<?php
namespace Correction\Api\Adapter;

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
        /** @var \Correction\Entity\CorrectionToken $entity */
        $data = $request->getContent();
        if (Request::CREATE === $request->getOperation()) {
            $resource = $this->getAdapter('resources')->findEntity($data['o:resource']['o:id']);
            $token = empty($data['o-module-correction:token'])
                ? $this->createToken()
                : $data['o-module-correction:token'];
            $email = empty($data['o:email']) ? null : $data['o:email'];
            $expire = empty($data['o-module-correction:expire']) ? null : $data['o-module-correction:expire'];
            $entity
                ->setResource($resource)
                ->setToken($token)
                ->setEmail($email)
                ->setExpire($expire)
                ->setCreated(new \DateTime('now'))
                ->setAccessed(null);
            ;
        } elseif (Request::UPDATE === $request->getOperation()) {
            if (isset($data['o-module-correction:accessed'])) {
                $accessed = strtotime($data['o-module-correction:accessed'])
                    ? $data['o-module-correction:accessed']
                    : 'now';
                $entity
                    ->setAccessed(new \DateTime($accessed));
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

    /**
     * Create a random token string.
     *
     * @return string
     */
    protected function createToken()
    {
        $entityManager = $this->getEntityManager();
        $repository = $entityManager->getRepository($this->getEntityClass());

        $tokenString = PHP_VERSION_ID < 70000
            ? function() { return sha1(mt_rand()); }
            : function() { return substr(str_replace(['+', '/', '-', '='], '', base64_encode(random_bytes(16))), 0, 10); };

        // Check if the token is unique.
        do {
            $token = $tokenString();
            $result = $repository->findOneBy(['token' => $token]);
            if (!$result) {
                break;
            }
        } while (true);

        return $token;
    }
}

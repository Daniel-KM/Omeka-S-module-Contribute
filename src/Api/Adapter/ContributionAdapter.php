<?php declare(strict_types=1);
namespace Contribute\Api\Adapter;

use Doctrine\ORM\QueryBuilder;
use Omeka\Api\Adapter\AbstractEntityAdapter;
use Omeka\Api\Request;
use Omeka\Entity\EntityInterface;
use Omeka\Stdlib\ErrorStore;

class ContributionAdapter extends AbstractEntityAdapter
{
    protected $sortFields = [
        'id' => 'id',
        'resource' => 'resource',
        'owner' => 'owner',
        'email' => 'email',
        'reviewed' => 'reviewed',
        'token' => 'token',
        'created' => 'created',
        'modified' => 'modified',
    ];

    public function getResourceName()
    {
        return 'contributions';
    }

    public function getRepresentationClass()
    {
        return \Contribute\Api\Representation\ContributionRepresentation::class;
    }

    public function getEntityClass()
    {
        return \Contribute\Entity\Contribution::class;
    }

    public function hydrate(Request $request, EntityInterface $entity, ErrorStore $errorStore): void
    {
        // TODO Use shouldHydrate() and validateEntity().
        /** @var \Contribute\Entity\Contribution $entity */
        $data = $request->getContent();
        if (Request::CREATE === $request->getOperation()) {
            $this->hydrateOwner($request, $entity);
            $resource = empty($data['o:resource']['o:id'])
                ? null
                : $this->getAdapter('resources')->findEntity($data['o:resource']['o:id']);
            $token = empty($data['o-module-contribute:token'])
                ? null
                : $this->getAdapter('contribution_tokens')->findEntity($data['o-module-contribute:token']['o:id']);
            $email = empty($data['o:email']) ? null : $data['o:email'];
            $reviewed = !empty($data['o-module-contribute:reviewed']);
            $proposal = empty($data['o-module-contribute:proposal'])
                ? []
                : $data['o-module-contribute:proposal'];
            $entity
                ->setResource($resource)
                ->setToken($token)
                ->setEmail($email)
                ->setReviewed($reviewed)
                ->setProposal($proposal);
        } elseif (Request::UPDATE === $request->getOperation()) {
            if (!$entity->getResource() && $this->shouldHydrate($request, 'o:resource', $data)) {
                $resource = empty($data['o:resource']['o:id'])
                    ? null
                    : $this->getAdapter('resources')->findEntity($data['o:resource']['o:id']);
                if ($resource) {
                    $entity
                        ->setResource($resource);
                }
            }
            if ($this->shouldHydrate($request, 'o-module-contribute:reviewed', $data)) {
                $reviewed = !empty($data['o-module-contribute:reviewed']);
                $entity
                    ->setReviewed($reviewed);
            }
            if ($this->shouldHydrate($request, 'o-module-contribute:proposal', $data)) {
                $proposal = empty($data['o-module-contribute:proposal'])
                    ? []
                    : $data['o-module-contribute:proposal'];
                $entity
                    ->setProposal($proposal);
            }
        }

        $this->updateTimestamps($request, $entity);
    }

    public function buildQuery(QueryBuilder $qb, array $query): void
    {
        $expr = $qb->expr();

        if (isset($query['resource_id'])) {
            if (!is_array($query['resource_id'])) {
                $query['resource_id'] = [$query['resource_id']];
            }
            $resourceAlias = $this->createAlias();
            $qb->innerJoin(
                'omeka_root.resource',
                $resourceAlias
            );
            $qb->andWhere($expr->in(
                $resourceAlias . '.id',
                $this->createNamedParameter($qb, $query['resource_id'])
            ));
        }

        if (isset($query['owner_id']) && is_numeric($query['owner_id'])) {
            $userAlias = $this->createAlias();
            $qb->innerJoin(
                'omeka_root.owner',
                $userAlias
            );
            $qb->andWhere($expr->eq(
                "$userAlias.id",
                $this->createNamedParameter($qb, $query['owner_id']))
            );
        }

        if (isset($query['email'])) {
            $qb->andWhere($expr->eq(
                'omeka_root.email',
                $this->createNamedParameter($qb, $query['email'])
            ));
        }

        if (isset($query['reviewed']) && is_numeric($query['reviewed'])) {
            $qb->andWhere($expr->eq(
                'omeka_root.reviewed',
                $this->createNamedParameter($qb, (bool) $query['reviewed'])
            ));
        }

        if (isset($query['token_id'])) {
            if (!is_array($query['token_id'])) {
                $query['token_id'] = [$query['token_id']];
            }
            $resourceAlias = $this->createAlias();
            $qb->innerJoin(
                'omeka_root.token',
                $resourceAlias
            );
            $qb->andWhere($expr->eq(
                $resourceAlias . '.id',
                $this->createNamedParameter($qb, $query['token_id'])
            ));
        }

        // TODO Add time comparison (see modules AdvancedSearchPlus or Next).
        if (isset($query['created'])) {
            $qb->andWhere($expr->eq(
                'omeka_root.created',
                $this->createNamedParameter($qb, $query['created'])
            ));
        }

        if (isset($query['modified'])) {
            $qb->andWhere($expr->eq(
                'omeka_root.modified',
                $this->createNamedParameter($qb, $query['modified'])
            ));
        }
    }
}

<?php declare(strict_types=1);

namespace Contribute\Api\Adapter;

use Doctrine\ORM\QueryBuilder;
use Omeka\Api\Adapter\AbstractEntityAdapter;
use Omeka\Api\Request;
use Omeka\Entity\EntityInterface;
use Omeka\Stdlib\ErrorStore;
use Omeka\Stdlib\Message;

class ContributionAdapter extends AbstractEntityAdapter
{
    protected $sortFields = [
        'id' => 'id',
        'resource' => 'resource',
        'owner' => 'owner',
        'email' => 'email',
        'patch' => 'patch',
        'submitted' => 'submitted',
        'reviewed' => 'reviewed',
        'token' => 'token',
        'created' => 'created',
        'modified' => 'modified',
    ];

    protected $scalarFields = [
        'id' => 'id',
        'resource' => 'resource',
        'owner' => 'owner',
        'email' => 'email',
        'patch' => 'patch',
        'submitted' => 'submitted',
        'reviewed' => 'reviewed',
        'proposal' => 'proposal',
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

        if (isset($query['patch']) && is_numeric($query['patch'])) {
            $qb->andWhere($expr->eq(
                'omeka_root.patch',
                $this->createNamedParameter($qb, (bool) $query['patch'])
            ));
        }

        if (isset($query['submitted']) && is_numeric($query['submitted'])) {
            $qb->andWhere($expr->eq(
                'omeka_root.submitted',
                $this->createNamedParameter($qb, (bool) $query['submitted'])
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

        if (isset($query['resource_template_id'])) {
            $ids = $query['resource_template_id'];
            if (!is_array($ids)) {
                $ids = [$ids];
            }
            $ids = array_filter($ids);
            if ($ids) {
                // Not available in orm, but via direct dbal sql.
                $sql = <<<SQL
SELECT `id`
FROM `contribution`
WHERE JSON_EXTRACT(`proposal`, "$.template") IN (:templates);
SQL;
            /** @var \Doctrine\DBAL\Connection $connection */
                $connection = $this->getServiceLocator()->get('Omeka\Connection');
                $contributionIds = $connection->executeQuery($sql, ['templates' => $query['resource_template_id']], ['templates' => \Doctrine\DBAL\Connection::PARAM_INT_ARRAY])->fetchFirstColumn();
                $contributionIds = array_map('intval', $contributionIds);
                if ($contributionIds) {
                    $qb->andWhere($expr->in(
                        'omeka_root.id',
                        $this->createNamedParameter($qb, $contributionIds)
                    ));
                } else {
                    $qb->andWhere($expr->eq(
                        'omeka_root.id',
                        $this->createNamedParameter($qb, 0)
                    ));
                }
            }
        }

        /** @experimental */
        if (isset($query['property'])) {
            foreach ($query['property'] as $propertyData) {
                $property = $propertyData['property'] ?? null;
                if (is_null($property) || !preg_match('~^[\w-]+\:[\w-]+$~i', $property)) {
                    $qb->andWhere($expr->eq(
                        'omeka_root.id',
                        $this->createNamedParameter($qb, 0)
                    ));
                } else {
                    $type = $propertyData['type'] ?? 'eq';
                    $types = [
                        'eq' => '@value',
                        'res' => '@resource',
                    ];
                    $keyType = $types[$type] ?? '@value';
                    $text = $propertyData['text'] ?? null;
                    // Not available in orm, but via direct dbal sql.
                    $sql = <<<SQL
SELECT `id`
FROM `contribution`
WHERE JSON_EXTRACT(`proposal`, "$.{$property}[*].proposed.{$keyType}") IN (:values);
SQL;
                    /** @var \Doctrine\DBAL\Connection $connection */
                    $text = is_array($text) ? $text : [$text];
                    foreach ($text as &$t) {
                        if ($keyType === '@resource') {
                            $t = '[' . (int) $t . ']';
                        } else {
                            $t = '[' . json_encode($t, 320) . ']';
                        }
                    }
                    unset($t);
                    $connection = $this->getServiceLocator()->get('Omeka\Connection');
                    $contributionIds = $connection->executeQuery($sql, ['values' => $text], ['values' => \Doctrine\DBAL\Connection::PARAM_STR_ARRAY])->fetchFirstColumn();
                    $contributionIds = array_map('intval', $contributionIds);
                    if ($contributionIds) {
                        $qb->andWhere($expr->in(
                            'omeka_root.id',
                            $this->createNamedParameter($qb, $contributionIds)
                        ));
                    } else {
                        $qb->andWhere($expr->eq(
                            'omeka_root.id',
                            $this->createNamedParameter($qb, 0)
                        ));
                    }
                }
            }
        }
    }

    public function validateRequest(Request $request, ErrorStore $errorStore): void
    {
        $data = $request->getContent();
        if (array_key_exists('o-module-contribute:proposal', $data)) {
            $proposal = $data['o-module-contribute:proposal'];
            $check = $this->checkProposedFiles($proposal);
            if (!is_null($check)) {
                $errorStore->addError('file', $check);
            }
        }
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
            $isPatch = !empty($resource);
            $submitted = !empty($data['o-module-contribute:submitted']);
            $reviewed = !empty($data['o-module-contribute:reviewed']);
            $proposal = empty($data['o-module-contribute:proposal'])
                ? []
                : $this->uploadProposedFiles($data['o-module-contribute:proposal']);
            $entity
                ->setResource($resource)
                ->setToken($token)
                ->setEmail($email)
                ->setPatch($isPatch)
                ->setSubmitted($submitted)
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
            // "patch" is an historical data that cannot be updated.
            if ($this->shouldHydrate($request, 'o-module-contribute:submitted', $data)) {
                $submitted = !empty($data['o-module-contribute:submitted']);
                $entity
                    ->setSubmitted($submitted);
            }
            if ($this->shouldHydrate($request, 'o-module-contribute:reviewed', $data)) {
                $reviewed = !empty($data['o-module-contribute:reviewed']);
                $entity
                    ->setReviewed($reviewed);
            }
            if ($this->shouldHydrate($request, 'o-module-contribute:proposal', $data)) {
                $proposal = empty($data['o-module-contribute:proposal'])
                    ? []
                    : $this->uploadProposedFiles($data['o-module-contribute:proposal']);
                $entity
                    ->setProposal($proposal);
            }
        }

        $this->updateTimestamps($request, $entity);
    }

    /**
     * Check proposed files.
     *
     * The files are already checked via controller, but check for direct api
     * process.
     *
     *@todo Use the error store when the form will be ready.
     */
    protected function checkProposedFiles(array $proposal): ?Message
    {
        foreach ($proposal['media'] ?? [] as $key => $mediaFiles) {
            foreach ($mediaFiles['file'] ?? [] as $mediaFile) {
                if (!empty($mediaFile['proposed']['store'])) {
                    continue;
                }
                $uploaded = $mediaFile['proposed']['file'] ?? [];
                if (empty($uploaded)) {
                    unset($proposal['media'][$key]['file']);
                    continue;
                }
                if ($uploaded['error'] || !$uploaded['size']) {
                    return new Message(
                        'Invalid or empty file for key %s', // @translate
                        $key
                    );
                } else {
                    // Don't use uploader here, but only in adapter, else
                    // Laminas will believe it's an attack after renaming.
                    /** @var \Omeka\File\TempFileFactory $tempFile */
                    $tempFile = $this->getServiceLocator()->get(\Omeka\File\TempFileFactory::class)->build();
                    $tempFile->setSourceName($uploaded['name']);
                    $tempFile->setTempPath($uploaded['tmp_name']);
                    if (!(new \Omeka\File\Validator())->validate($tempFile)) {
                        return new Message(
                            'Invalid file type for key %s', // @translate
                            $key
                        );
                    }
                }
            }
        }
        return null;
    }

    /**
     * Upload files and store the path in the proposal.
     */
    protected function uploadProposedFiles(array $proposal): array
    {
        foreach ($proposal['media'] ?? [] as $key => $mediaFiles) {
            $proposal['media'][$key]['file'] = empty($mediaFiles['file']) ? [] : array_values($mediaFiles['file']);
            foreach ($proposal['media'][$key]['file'] as $mediaFile) {
                if (!empty($mediaFile['proposed']['store'])) {
                    continue;
                }
                $uploaded = $mediaFile['proposed']['file'] ?? [];
                if (empty($uploaded)) {
                    unset($proposal['media'][$key]['file']);
                    continue;
                }
                if ($uploaded['error'] || !$uploaded['size']) {
                    unset($proposal['media'][$key]['file']);
                } else {
                    /** @var \Omeka\File\Uploader $uploader */
                    $uploader = $this->getServiceLocator()->get(\Omeka\File\Uploader::class);
                    $tempFile = $uploader->upload($uploaded);
                    if (!$tempFile) {
                        unset($proposal['media'][$key]['file']);
                    } else {
                        $tempFile->setSourceName($uploaded['name']);
                        if (!(new \Omeka\File\Validator())->validate($tempFile)) {
                            unset($proposal['media'][$key]['file']);
                        } else {
                            $extension = $tempFile->getExtension();
                            $filename = $tempFile->getStorageId()
                                . (strlen((string) $extension) ? '.' . $extension : '');
                            $proposal['media'][$key]['file'][0]['proposed']['@value'] = $uploaded['name'];
                            $proposal['media'][$key]['file'][0]['proposed']['store'] = $filename;
                            unset($proposal['media'][$key]['file'][0]['proposed']['file']);
                            $tempFile->store('contribution');
                            $tempFile->delete();
                        }
                    }
                }
            }
        }
        return $proposal;
    }
}

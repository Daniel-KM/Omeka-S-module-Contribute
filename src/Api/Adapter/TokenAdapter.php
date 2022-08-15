<?php declare(strict_types=1);

namespace Contribute\Api\Adapter;

use Doctrine\ORM\QueryBuilder;
use Omeka\Api\Adapter\AbstractEntityAdapter;
use Omeka\Api\Request;
use Omeka\Entity\EntityInterface;
use Omeka\Stdlib\ErrorStore;

class TokenAdapter extends AbstractEntityAdapter
{
    protected $sortFields = [
        'id' => 'id',
        'resource' => 'resource',
        'token' => 'token',
        'email' => 'email',
        'expire' => 'expire',
        'created' => 'created',
        'accessed' => 'accessed',
    ];

    protected $scalarFields = [
        'id' => 'id',
        'resource' => 'resource',
        'token' => 'token',
        'email' => 'email',
        'expire' => 'expire',
        'created' => 'created',
        'accessed' => 'accessed',
    ];

    public function getResourceName()
    {
        return 'contribution_tokens';
    }

    public function getRepresentationClass()
    {
        return \Contribute\Api\Representation\TokenRepresentation::class;
    }

    public function getEntityClass()
    {
        return \Contribute\Entity\Token::class;
    }

    public function hydrate(Request $request, EntityInterface $entity, ErrorStore $errorStore): void
    {
        /** @var \Contribute\Entity\Token $entity */
        $data = $request->getContent();
        if (Request::CREATE === $request->getOperation()) {
            $resource = $this->getAdapter('resources')->findEntity($data['o:resource']['o:id']);
            $token = empty($data['o-module-contribute:token'])
                ? $this->createToken()
                : $data['o-module-contribute:token'];
            $email = empty($data['o:email']) ? null : $data['o:email'];
            $expire = empty($data['o-module-contribute:expire']) ? null : $data['o-module-contribute:expire'];
            $entity
                ->setResource($resource)
                ->setToken($token)
                ->setEmail($email)
                ->setExpire($expire)
                ->setCreated(new \DateTime('now'))
                ->setAccessed(null);
        } elseif (Request::UPDATE === $request->getOperation()) {
            if (array_key_exists('o-module-contribute:expire', $data)) {
                $expire = strtotime($data['o-module-contribute:expire'])
                    ? $data['o-module-contribute:expire']
                    : 'now';
                $entity
                    ->setExpire(new \DateTime($expire));
            }
            if (array_key_exists('o-module-contribute:accessed', $data)) {
                $accessed = strtotime($data['o-module-contribute:accessed'])
                    ? $data['o-module-contribute:accessed']
                    : 'now';
                $entity
                    ->setAccessed(new \DateTime($accessed));
            }
        }
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

        if (isset($query['token'])) {
            $qb->andWhere($expr->eq(
                'omeka_root.token',
                $this->createNamedParameter($qb, $query['token'])
            ));
        }

        if (isset($query['email'])) {
            $qb->andWhere($expr->eq(
                'omeka_root.email',
                $this->createNamedParameter($qb, $query['email'])
            ));
        }

        if (isset($query['used'])) {
            $resourceAlias = $this->createAlias();
            if ($query['used']) {
                $qb->innerJoin(
                    \Contribute\Entity\Contribution::class,
                    $resourceAlias,
                    'WITH',
                    $expr->eq($resourceAlias . '.token', 'omeka_root.id')
                );
            } else {
                $qb->leftJoin(
                    \Contribute\Entity\Contribution::class,
                    $resourceAlias,
                    'WITH',
                    $expr->eq($resourceAlias . '.token', 'omeka_root.id')
                );
                $qb->andWhere($expr->isNull($resourceAlias . '.id'));
            }
        }

        $this->searchDateTime($qb, $query);
    }

    public function preprocessBatchUpdate(array $data, Request $request)
    {
        // Preprocess acts as a filter to keep only the specified data keys.

        $rawData = $request->getContent();
        $data = parent::preprocessBatchUpdate($data, $request);

        if (isset($rawData['o-module-contribute:expire'])) {
            $data['o-module-contribute:expire'] = $rawData['o-module-contribute:expire'];
        }

        return $data;
    }

    /**
     * Build query on date time (created/modified), partial date/time allowed.
     *
     * The query format is inspired by Doctrine and properties.
     *
     * Query format:
     *
     * - datetime[{index}][joiner]: "and" OR "or" joiner with previous query
     * - datetime[{index}][field]: the field "created" or "modified"
     * - datetime[{index}][type]: search type
     *   - gt: greater than (after)
     *   - gte: greater than or equal
     *   - eq: is exactly
     *   - neq: is not exactly
     *   - lte: lower than or equal
     *   - lt: lower than (before)
     *   - ex: has any value
     *   - nex: has no value
     * - datetime[{index}][value]: search date time (sql format: "2017-11-07 17:21:17",
     *   partial date/time allowed ("2018-05", etc.).
     *
     * @todo Factorize searchDateTime with module AdvancedSearch module.
     */
    protected function searchDateTime(QueryBuilder $qb, array $query): void
    {
        $query = $this->normalizeDateTime($query);
        if (empty($query['datetime'])) {
            return;
        }

        $adapter = $this;
        $expr = $qb->expr();

        $where = '';

        foreach ($query['datetime'] as $queryRow) {
            $joiner = $queryRow['joiner'];
            $field = $queryRow['field'];
            $type = $queryRow['type'];
            $value = $queryRow['value'];

            // By default, sql replace missing time by 00:00:00, but this is not
            // clear for the user. And it doesn't allow partial date/time.
            switch ($type) {
                case 'gt':
                    // TODO Use mb_substr_replace.
                    if (mb_strlen($value) < 19) {
                        $value = substr_replace('9999-12-31 23:59:59', $value, 0, mb_strlen($value) - 19);
                    }
                    $param = $adapter->createNamedParameter($qb, $value);
                    $predicateExpr = $expr->gt('omeka_root.' . $field, $param);
                    break;
                case 'gte':
                    if (mb_strlen($value) < 19) {
                        $value = substr_replace('0000-01-01 00:00:00', $value, 0, mb_strlen($value) - 19);
                    }
                    $param = $adapter->createNamedParameter($qb, $value);
                    $predicateExpr = $expr->gte('omeka_root.' . $field, $param);
                    break;
                case 'eq':
                    if (mb_strlen($value) < 19) {
                        $valueFrom = substr_replace('0000-01-01 00:00:00', $value, 0, mb_strlen($value) - 19);
                        $valueTo = substr_replace('9999-12-31 23:59:59', $value, 0, mb_strlen($value) - 19);
                        $paramFrom = $adapter->createNamedParameter($qb, $valueFrom);
                        $paramTo = $adapter->createNamedParameter($qb, $valueTo);
                        $predicateExpr = $expr->between('omeka_root.' . $field, $paramFrom, $paramTo);
                    } else {
                        $param = $adapter->createNamedParameter($qb, $value);
                        $predicateExpr = $expr->eq('omeka_root.' . $field, $param);
                    }
                    break;
                case 'neq':
                    if (mb_strlen($value) < 19) {
                        $valueFrom = substr_replace('0000-01-01 00:00:00', $value, 0, mb_strlen($value) - 19);
                        $valueTo = substr_replace('9999-12-31 23:59:59', $value, 0, mb_strlen($value) - 19);
                        $paramFrom = $adapter->createNamedParameter($qb, $valueFrom);
                        $paramTo = $adapter->createNamedParameter($qb, $valueTo);
                        $predicateExpr = $expr->not(
                            $expr->between('omeka_root.' . $field, $paramFrom, $paramTo)
                            );
                    } else {
                        $param = $adapter->createNamedParameter($qb, $value);
                        $predicateExpr = $expr->neq('omeka_root.' . $field, $param);
                    }
                    break;
                case 'lte':
                    if (mb_strlen($value) < 19) {
                        $value = substr_replace('9999-12-31 23:59:59', $value, 0, mb_strlen($value) - 19);
                    }
                    $param = $adapter->createNamedParameter($qb, $value);
                    $predicateExpr = $expr->lte('omeka_root.' . $field, $param);
                    break;
                case 'lt':
                    if (mb_strlen($value) < 19) {
                        $value = substr_replace('0000-01-01 00:00:00', $value, 0, mb_strlen($value) - 19);
                    }
                    $param = $adapter->createNamedParameter($qb, $value);
                    $predicateExpr = $expr->lt('omeka_root.' . $field, $param);
                    break;
                case 'ex':
                    $predicateExpr = $expr->isNotNull('omeka_root.' . $field);
                    break;
                case 'nex':
                    $predicateExpr = $expr->isNull('omeka_root.' . $field);
                    break;
                default:
                    continue 2;
            }

            // First expression has no joiner.
            if ($where === '') {
                $where = '(' . $predicateExpr . ')';
            } elseif ($joiner === 'or') {
                $where .= ' OR (' . $predicateExpr . ')';
            } else {
                $where .= ' AND (' . $predicateExpr . ')';
            }
        }

        if ($where) {
            $qb->andWhere($where);
        }
    }

    /**
     * Normalize the query for the datetime.
     *
     * @todo Factorize normalizeDateTime with module AdvancedSearch module.
     */
    protected function normalizeDateTime(array $query): array
    {
        if (empty($query['datetime'])) {
            return $query;
        }

        // Manage a single date time.
        if (!is_array($query['datetime'])) {
            $query['datetime'] = [[
                'joiner' => 'and',
                'field' => 'created',
                'type' => 'eq',
                'value' => $query['datetime'],
            ]];
            return $query;
        }

        foreach ($query['datetime'] as $key => &$queryRow) {
            if (empty($queryRow)) {
                unset($query['datetime'][$key]);
                continue;
            }

            // Clean query and manage default values.
            if (is_array($queryRow)) {
                $queryRow = array_map('strtolower', array_map('trim', $queryRow));
                if (empty($queryRow['joiner'])) {
                    $queryRow['joiner'] = 'and';
                } else {
                    if (!in_array($queryRow['joiner'], ['and', 'or'])) {
                        unset($query['datetime'][$key]);
                        continue;
                    }
                }

                if (empty($queryRow['field'])) {
                    $queryRow['field'] = 'created';
                } else {
                    if (!in_array($queryRow['field'], ['created', 'modified', 'expire'])) {
                        unset($query['datetime'][$key]);
                        continue;
                    }
                }

                if (empty($queryRow['type'])) {
                    $queryRow['type'] = 'eq';
                } else {
                    // "ex" and "nex" are useful only for the modified time.
                    if (!in_array($queryRow['type'], ['lt', 'lte', 'eq', 'gte', 'gt', 'neq', 'ex', 'nex'])) {
                        unset($query['datetime'][$key]);
                        continue;
                    }
                }

                if (in_array($queryRow['type'], ['ex', 'nex'])) {
                    $query['datetime'][$key]['value'] = '';
                } elseif (empty($queryRow['value'])) {
                    unset($query['datetime'][$key]);
                    continue;
                } else {
                    // Date time cannot be longer than 19 numbers.
                    // But user can choose a year only, etc.
                }
            } else {
                $queryRow = [
                    'joiner' => 'and',
                    'field' => 'created',
                    'type' => 'eq',
                    'value' => $queryRow,
                ];
            }
        }

        return $query;
    }

    /**
     * Create a random token string.
     */
    protected function createToken(): string
    {
        $entityManager = $this->getEntityManager();
        $repository = $entityManager->getRepository($this->getEntityClass());

        // Check if the token is unique.
        do {
            $token = substr(str_replace(['+', '/', '='], ['', '', ''], base64_encode(random_bytes(128))), 0, 10);
            $result = $repository->findOneBy(['token' => $token]);
            if (!$result) {
                break;
            }
        } while (true);

        return $token;
    }
}

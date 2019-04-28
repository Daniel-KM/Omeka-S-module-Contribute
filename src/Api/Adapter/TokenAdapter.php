<?php
namespace Correction\Api\Adapter;

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
        return \Correction\Api\Representation\TokenRepresentation::class;
    }

    public function getEntityClass()
    {
        return \Correction\Entity\Token::class;
    }

    public function hydrate(Request $request, EntityInterface $entity, ErrorStore $errorStore)
    {
        /** @var \Correction\Entity\Token $entity */
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
            if (array_key_exists('o-module-correction:expire', $data)) {
                $expire = strtotime($data['o-module-correction:expire'])
                    ? $data['o-module-correction:expire']
                    : null;
                $entity
                    ->setExpire(new \DateTime($expire));
            }
            if (array_key_exists('o-module-correction:accessed', $data)) {
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
        $expr = $qb->expr();
        if (isset($query['id'])) {
            $qb->andWhere($expr->eq(
                $this->getEntityClass() . '.id',
                $this->createNamedParameter($qb, $query['id'])
            ));
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
            $qb->andWhere($expr->in(
                $resourceAlias . '.id',
                $this->createNamedParameter($qb, $query['resource_id'])
            ));
        }

        if (isset($query['token'])) {
            $qb->andWhere($expr->eq(
                $this->getEntityClass() . '.token',
                $this->createNamedParameter($qb, $query['token'])
            ));
        }

        if (isset($query['email'])) {
            $qb->andWhere($expr->eq(
                $this->getEntityClass() . '.email',
                $this->createNamedParameter($qb, $query['email'])
            ));
        }

        $this->searchDateTime($qb, $query);
    }

    public function preprocessBatchUpdate(array $data, Request $request)
    {
        // Preprocess acts as a filter to keep only the specified data keys.

        $rawData = $request->getContent();
        $data = parent::preprocessBatchUpdate($data, $request);

        if (isset($rawData['o-module-correction:expire'])) {
            $data['o-module-correction:expire'] = $rawData['o-module-correction:expire'];
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
     * From AdvancedSearchPlus module.
     *
     * @param QueryBuilder $qb
     * @param array $query
     */
    protected function searchDateTime(QueryBuilder $qb, array $query)
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

            $resourceClass = $adapter->getEntityClass();

            // By default, sql replace missing time by 00:00:00, but this is not
            // clear for the user. And it doesn't allow partial date/time.
            switch ($type) {
                case 'gt':
                    if (strlen($value) < 19) {
                        $value = substr_replace('9999-12-31 23:59:59', $value, 0, strlen($value) - 19);
                    }
                    $param = $adapter->createNamedParameter($qb, $value);
                    $predicateExpr = $expr->gt($resourceClass . '.' . $field, $param);
                    break;
                case 'gte':
                    if (strlen($value) < 19) {
                        $value = substr_replace('0000-01-01 00:00:00', $value, 0, strlen($value) - 19);
                    }
                    $param = $adapter->createNamedParameter($qb, $value);
                    $predicateExpr = $expr->gte($resourceClass . '.' . $field, $param);
                    break;
                case 'eq':
                    if (strlen($value) < 19) {
                        $valueFrom = substr_replace('0000-01-01 00:00:00', $value, 0, strlen($value) - 19);
                        $valueTo = substr_replace('9999-12-31 23:59:59', $value, 0, strlen($value) - 19);
                        $paramFrom = $adapter->createNamedParameter($qb, $valueFrom);
                        $paramTo = $adapter->createNamedParameter($qb, $valueTo);
                        $predicateExpr = $expr->between($resourceClass . '.' . $field, $paramFrom, $paramTo);
                    } else {
                        $param = $adapter->createNamedParameter($qb, $value);
                        $predicateExpr = $expr->eq($resourceClass . '.' . $field, $param);
                    }
                    break;
                case 'neq':
                    if (strlen($value) < 19) {
                        $valueFrom = substr_replace('0000-01-01 00:00:00', $value, 0, strlen($value) - 19);
                        $valueTo = substr_replace('9999-12-31 23:59:59', $value, 0, strlen($value) - 19);
                        $paramFrom = $adapter->createNamedParameter($qb, $valueFrom);
                        $paramTo = $adapter->createNamedParameter($qb, $valueTo);
                        $predicateExpr = $expr->not(
                            $expr->between($resourceClass . '.' . $field, $paramFrom, $paramTo)
                            );
                    } else {
                        $param = $adapter->createNamedParameter($qb, $value);
                        $predicateExpr = $expr->neq($resourceClass . '.' . $field, $param);
                    }
                    break;
                case 'lte':
                    if (strlen($value) < 19) {
                        $value = substr_replace('9999-12-31 23:59:59', $value, 0, strlen($value) - 19);
                    }
                    $param = $adapter->createNamedParameter($qb, $value);
                    $predicateExpr = $expr->lte($resourceClass . '.' . $field, $param);
                    break;
                case 'lt':
                    if (strlen($value) < 19) {
                        $value = substr_replace('0000-01-01 00:00:00', $value, 0, strlen($value) - 19);
                    }
                    $param = $adapter->createNamedParameter($qb, $value);
                    $predicateExpr = $expr->lt($resourceClass . '.' . $field, $param);
                    break;
                case 'ex':
                    $predicateExpr = $expr->isNotNull($resourceClass . '.' . $field);
                    break;
                case 'nex':
                    $predicateExpr = $expr->isNull($resourceClass . '.' . $field);
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
     * From AdvancedSearchPlus module.
     *
     * @param array $query
     * @return array
     */
    protected function normalizeDateTime(array $query)
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
     *
     * @return string
     */
    protected function createToken()
    {
        $entityManager = $this->getEntityManager();
        $repository = $entityManager->getRepository($this->getEntityClass());

        $tokenString = PHP_VERSION_ID < 70000
            ? function () {
                return sha1(mt_rand());
            }
            : function () {
                return substr(str_replace(['+', '/', '-', '='], '', base64_encode(random_bytes(16))), 0, 10);
            };

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

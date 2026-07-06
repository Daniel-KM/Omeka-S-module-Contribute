<?php declare(strict_types=1);

namespace Contribute\Api\Adapter;

use Common\Api\Adapter\CommonAdapterTrait;
use Common\Stdlib\PsrMessage;
use Doctrine\ORM\Query\Expr\Comparison;
use Doctrine\ORM\QueryBuilder;
use Omeka\Api\Adapter\AbstractEntityAdapter;
use Omeka\Api\Request;
use Omeka\Entity\EntityInterface;
use Omeka\Stdlib\ErrorStore;

class ContributionAdapter extends AbstractEntityAdapter
{
    use CommonAdapterTrait;

    protected $sortFields = [
        'id' => 'id',
        'resource' => 'resource',
        'owner' => 'owner',
        'email' => 'email',
        'patch' => 'patch',
        'submitted' => 'submitted',
        'undertaken' => 'undertaken',
        'validated' => 'validated',
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
        'undertaken' => 'undertaken',
        'validated' => 'validated',
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

    /**
     * Build the search query for contributions.
     *
     * Filtering by proposal content (property[], filter[],
     * resource_template_id, resource_class_id) is a partial reimplementation
     * operating on the JSON `proposal` column, not on the underlying resource.
     * It covers the most common cases (types eq, res, in) but is not a full
     * AdvancedSearch DSL port. Unsupported types and unknown top-level
     * arguments are logged with a warning — check the logs when configuring
     * queries in settings (e.g. contribute_notify_recipients) or in resource
     * templates.
     */
    public function buildQuery(QueryBuilder $qb, array $query): void
    {
        $expr = $qb->expr();

        if (isset($query['resource_id']) && $query['resource_id'] !== '' && $query['resource_id'] !== []) {
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

        if (isset($query['owner_id']) && $query['owner_id'] !== '' && $query['owner_id'] !== []) {
            if (!is_array($query['owner_id'])) {
                $query['owner_id'] = [$query['owner_id']];
            }
            $userAlias = $this->createAlias();
            $qb->innerJoin(
                'omeka_root.owner',
                $userAlias
            );
            $qb->andWhere($expr->in(
                "$userAlias.id",
                $this->createNamedParameter($qb, $query['owner_id']))
            );
        }

        if (isset($query['email']) && $query['email'] !== '') {
            $qb->andWhere($expr->eq(
                'omeka_root.email',
                $this->createNamedParameter($qb, $query['email'])
            ));
        }

        // Note: "00" is used for "false" in the form to distinguish from empty
        // string "". Check for non-empty values that are numeric or boolean.
        if (isset($query['patch']) && $query['patch'] !== '' && (is_numeric($query['patch']) || is_bool($query['patch']))) {
            $qb->andWhere($expr->eq(
                'omeka_root.patch',
                // The double cast manage the three-state radio ("", "1", "00").
                $this->createNamedParameter($qb, (bool) (int) $query['patch'])
            ));
        }

        if (isset($query['submitted']) && $query['submitted'] !== '' && (is_numeric($query['submitted']) || is_bool($query['submitted']))) {
            $qb->andWhere($expr->eq(
                'omeka_root.submitted',
                // The double cast manage the three-state radio ("", "1", "00").
                $this->createNamedParameter($qb, (bool) (int) $query['submitted'])
            ));
        }

        if (isset($query['undertaken']) && $query['undertaken'] !== '' && (is_numeric($query['undertaken']) || is_bool($query['undertaken']))) {
            $qb->andWhere($expr->eq(
                'omeka_root.undertaken',
                // The double cast manage the three-state radio ("", "1", "00").
                $this->createNamedParameter($qb, (bool) (int) $query['undertaken'])
            ));
        }

        if (isset($query['validated']) && $query['validated'] !== '') {
            $val = $query['validated'];
            // Null is generally removed from query, so use string "null".
            if ($val === 'null') {
                $qb->andWhere($expr->isNull(
                    'omeka_root.validated'
                ));
            } elseif (is_numeric($val) || is_bool($val)) {
                $qb->andWhere($expr->eq(
                    'omeka_root.validated',
                    // The double cast manage the three-state radio ("", "1", "00").
                    $this->createNamedParameter($qb, (bool) (int) $val)
                ));
            } else {
                // Unknown value: return no results.
                $qb->andWhere($expr->eq(
                    'omeka_root.id',
                    $this->createNamedParameter($qb, 0)
                ));
            }
        }

        if (isset($query['token_id']) && $query['token'] !== '') {
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

        // TODO Add time comparison (see modules AdvancedSearch or Log).
        if (isset($query['created']) && $query['created'] !== '') {
            $this->buildQueryDateComparison($qb, $query, $query['created'], 'created');
        }

        if (isset($query['modified']) && $query['modified'] !== '') {
            $qb->andWhere($expr->eq(
                'omeka_root.modified',
                $this->createNamedParameter($qb, $query['modified'])
            ));
        }

        // Resolve resource_class_id to the set of templates using that class,
        // since the proposal JSON stores only the template id, not the class.
        if (isset($query['resource_class_id']) && $query['resource_class_id'] !== '' && $query['resource_class_id'] !== []) {
            $classIds = is_array($query['resource_class_id']) ? $query['resource_class_id'] : [$query['resource_class_id']];
            $classIds = array_filter(array_map('intval', $classIds));
            if ($classIds) {
                $templateIds = $this->getServiceLocator()->get('Omeka\ApiManager')
                    ->search('resource_templates', ['resource_class_id' => $classIds], ['returnScalar' => 'id'])
                    ->getContent();
                $templateIds = array_map('intval', array_values($templateIds));
                if (!$templateIds) {
                    $qb->andWhere($expr->eq(
                        'omeka_root.id',
                        $this->createNamedParameter($qb, 0)
                    ));
                } else {
                    $existing = $query['resource_template_id'] ?? null;
                    if ($existing === null || $existing === '' || $existing === []) {
                        $query['resource_template_id'] = $templateIds;
                    } else {
                        $query['resource_template_id'] = array_values(array_intersect(
                            is_array($existing) ? $existing : [$existing],
                            $templateIds
                        )) ?: [0];
                    }
                }
            }
            unset($query['resource_class_id']);
        }

        if (isset($query['resource_template_id']) && $query['resource_template_id'] !== '' && $query['resource_template_id'] !== []) {
            $ids = $query['resource_template_id'];
            if (!is_array($ids)) {
                $ids = [$ids];
            }
            $ids = array_filter($ids);
            if ($ids) {
                // Not available in orm, but via direct dbal sql.
                $sql = <<<'SQL'
                    SELECT `id`
                    FROM `contribution`
                    WHERE JSON_EXTRACT(`proposal`, "$.template") IN (:templates);
                    SQL;
                /** @var \Doctrine\DBAL\Connection $connection */
                $connection = $this->getServiceLocator()->get('Omeka\Connection');
                $contributionIds = $connection->executeQuery($sql, ['templates' => $ids], ['templates' => \Doctrine\DBAL\Connection::PARAM_INT_ARRAY])->fetchFirstColumn();
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

        // The search on the proposal is used for notifications when resource
        // template is not enough.

        // Normalize AdvancedSearch `filter[i][field/type/val]` into the
        // existing `property[]` and `resource_template_id` slots.
        // Multiple values inside a filter are or; multiple filters are and.
        // Filters operate on the contribution proposal, not on the underlying
        // resource.
        /** @var \Laminas\Log\LoggerInterface $logger */
        $logger = $this->getServiceLocator()->get('Omeka\Logger');

        if (!empty($query['filter']) && is_array($query['filter'])) {
            foreach ($query['filter'] as $filterRow) {
                if (!is_array($filterRow)) {
                    continue;
                }
                $fld = $filterRow['field'] ?? null;
                $val = $filterRow['val'] ?? null;
                if ($fld === null || $fld === '' || $val === null || $val === '' || $val === []) {
                    continue;
                }
                $type = $filterRow['type'] ?? 'eq';
                if (!in_array($type, ['eq', 'res', 'in'], true)) {
                    $logger->warn((new PsrMessage(
                        'Contribute search: filter type "{type}" is not supported and is treated as "eq".', // @translate
                        ['type' => $type]
                    ))->getMessage(), ['type' => $type]);
                }
                if ($fld === 'resource_template_id' || $fld === 'resource_class_id') {
                    $vals = is_array($val) ? array_values($val) : [$val];
                    $query[$fld] = array_merge(
                        isset($query[$fld]) && is_array($query[$fld])
                            ? $query[$fld]
                            : (isset($query[$fld]) ? [$query[$fld]] : []),
                        $vals
                    );
                    continue;
                }
                if (!preg_match('~^[\w-]+\:[\w-]+$~i', (string) $fld)) {
                    $logger->warn((new PsrMessage(
                        'Contribute search: filter field "{field}" is not a valid term and is ignored.', // @translate
                        ['field' => (string) $fld]
                    ))->getMessage(), ['field' => (string) $fld]);
                    continue;
                }
                $query['property'][] = [
                    'property' => $fld,
                    'type' => in_array($type, ['eq', 'res', 'in'], true) ? ($type === 'in' ? 'eq' : $type) : 'eq',
                    'text' => is_array($val) ? array_values($val) : $val,
                ];
            }
            unset($query['filter']);
        }

        if (isset($query['property']) && $query['property'] !== '' && $query['property'] !== []) {
            foreach ($query['property'] as $propertyData) {
                $property = $propertyData['property'] ?? null;
                if ($property === null || !preg_match('~^[\w-]+\:[\w-]+$~i', $property)) {
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
                    if (!isset($types[$type])) {
                        $logger->warn((new PsrMessage(
                            'Contribute search: property type "{type}" is not supported and is treated as "eq".', // @translate
                            ['type' => $type]
                        ))->getMessage(), ['type' => $type]);
                    }
                    $keyType = $types[$type] ?? '@value';
                    $text = $propertyData['text'] ?? null;
                    // Not available in orm, but via direct dbal sql.
                    $sql = <<<SQL
                        SELECT `id`
                        FROM `contribution`
                        WHERE JSON_EXTRACT(`proposal`, "$.{$property}[*].proposed.{$keyType}") IN (:values);
                        SQL;
                    /** @var \Doctrine\DBAL\Connection $connection */
                    $text = is_array($text) ? array_values($text) : [$text];
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

        if (isset($query['fulltext_search']) && $query['fulltext_search'] !== '') {
            $qb->andWhere($expr->like(
                'omeka_root.proposal',
                $this->createNamedParameter($qb, '%' . strtr($query['fulltext_search'], ['%' => '\%', '_' => '\_', '\\' => '\\\\']) . '%')
            ));
        }

        // Warn on unrecognized top-level query keys (silently accepted Omeka
        // standard keys are excluded). Helps diagnose typos in admin queries
        // configured via settings (e.g. contribute_notify_recipients).
        $knownKeys = [
            'id', 'ids', 'page', 'per_page', 'limit', 'offset',
            'sort_by', 'sort_order', 'search', 'return_scalar',
            'resource_id', 'owner_id', 'email', 'patch', 'submitted',
            'undertaken', 'validated', 'token_id', 'created', 'modified',
            'resource_template_id', 'resource_class_id', 'property', 'fulltext_search',
        ];
        $unknownKeys = array_diff(array_keys($query), $knownKeys);
        if ($unknownKeys) {
            $logger->warn((new PsrMessage(
                'Contribute search: ignored unsupported query arguments: {keys}.', // @translate
                ['keys' => implode(', ', $unknownKeys)]
            ))->getMessage(), ['keys' => $unknownKeys]);
        }
    }

    public function validateRequest(Request $request, ErrorStore $errorStore): void
    {
        $data = $request->getContent();
        if (array_key_exists('o-module-contribute:proposal', $data)) {
            $proposal = $data['o-module-contribute:proposal'];
            $check = $this->checkProposedFiles($proposal);
            if ($check !== null) {
                $errorStore->addError('file', $check);
            }
        }
    }

    public function hydrate(Request $request, EntityInterface $entity, ErrorStore $errorStore): void
    {
        // TODO Use shouldHydrate() and validateEntity().

        /** @var \Contribute\Entity\Contribution $entity */

        $data = $request->getContent();

        $entityManager = $this->getEntityManager();

        if (Request::CREATE === $request->getOperation()) {
            $this->hydrateOwner($request, $entity);
            $resource = empty($data['o:resource']['o:id'])
                ? null
                : $entityManager->find(\Omeka\Entity\Resource::class, $data['o:resource']['o:id']);
            $token = empty($data['o-module-contribute:token'])
                ? null
                : $entityManager->find(\Contribute\Entity\Token::class, $data['o-module-contribute:token']['o:id']);
            $email = empty($data['o:email']) ? null : $data['o:email'];
            $isPatch = !empty($resource);
            $submitted = !empty($data['o-module-contribute:submitted']);
            $undertaken = !empty($data['o-module-contribute:undertaken']);
            $validated = $data['o-module-contribute:validated'] ?? null;
            $validated = is_numeric($validated) || is_bool($validated) ? (bool) (int) $validated : null;
            $proposal = empty($data['o-module-contribute:proposal'])
                ? []
                : $this->uploadProposedFiles($data['o-module-contribute:proposal']);
            $entity
                ->setResource($resource)
                ->setToken($token)
                ->setEmail($email)
                ->setPatch($isPatch)
                ->setSubmitted($submitted)
                ->setUndertaken($undertaken)
                ->setValidated($validated)
                ->setProposal($proposal);
        } elseif (Request::UPDATE === $request->getOperation()) {
            if (!$entity->getResource() && $this->shouldHydrate($request, 'o:resource', $data)) {
                $resource = empty($data['o:resource']['o:id'])
                    ? null
                    : $entityManager->find(\Omeka\Entity\Resource::class, $data['o:resource']['o:id']);
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
            if ($this->shouldHydrate($request, 'o-module-contribute:undertaken', $data)) {
                $undertaken = !empty($data['o-module-contribute:undertaken']);
                $entity
                    ->setUndertaken($undertaken);
            }
            if ($this->shouldHydrate($request, 'o-module-contribute:validated', $data)) {
                $validated = $data['o-module-contribute:validated'];
                $validated = is_numeric($validated) || is_bool($validated) ? (bool) (int) $validated : null;
                $entity
                    ->setValidated($validated);
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
    protected function checkProposedFiles(array $proposal): ?PsrMessage
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
                    return new PsrMessage(
                        'Invalid or empty file for key {key}.', // @translate
                        ['key' => $key]
                    );
                } else {
                    // Don't use uploader here, but only in adapter, else
                    // Laminas will believe it's an attack after renaming.
                    /** @var \Omeka\File\TempFileFactory $tempFile */
                    $tempFile = $this->getServiceLocator()->get(\Omeka\File\TempFileFactory::class)->build();
                    $tempFile->setSourceName($uploaded['name']);
                    $tempFile->setTempPath($uploaded['tmp_name']);
                    if (!(new \Omeka\File\Validator())->validate($tempFile)) {
                        return new PsrMessage(
                            'Invalid file type for key {key}.', // @translate
                            ['key' => $key]
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
        $services = $this->getServiceLocator();
        $basePath = $services->get('Config')['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        foreach ($proposal['media'] ?? [] as $key => $mediaFiles) {
            $proposal['media'][$key]['file'] = empty($mediaFiles['file']) ? [] : array_values($mediaFiles['file']);
            foreach ($proposal['media'][$key]['file'] as $fileKey => $mediaFile) {
                if (!empty($mediaFile['proposed']['store'])) {
                    // Complete the size and the hash from the stored file when
                    // they are missing, in particular when the store is kept
                    // from a previous step of the form.
                    if (!isset($mediaFile['proposed']['sha256'])) {
                        $storePath = $basePath . '/contribution/' . $mediaFile['proposed']['store'];
                        if (strpos($mediaFile['proposed']['store'], '..') === false && file_exists($storePath)) {
                            $proposal['media'][$key]['file'][$fileKey]['proposed']['size'] = filesize($storePath);
                            $proposal['media'][$key]['file'][$fileKey]['proposed']['sha256'] = hash_file('sha256', $storePath);
                        }
                    }
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
                            // Store the size and the hash to check integrity
                            // and to recover files in case of an issue.
                            $proposal['media'][$key]['file'][0]['proposed']['@value'] = $uploaded['name'];
                            $proposal['media'][$key]['file'][0]['proposed']['store'] = $filename;
                            $proposal['media'][$key]['file'][0]['proposed']['size'] = $tempFile->getSize();
                            $proposal['media'][$key]['file'][0]['proposed']['sha256'] = $tempFile->getSha256();
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

    /**
     * Add a comparison condition to query from a date.
     *
     * @see \Annotate\Api\Adapter\QueryDateTimeTrait::searchDateTime()
     * @see \AiGenerator\Api\Adapter\AiRecordAdapter::buildQueryDateComparison()
     * @see \Contribute\Api\Adapter\ContributionAdapter::buildQueryDateComparison()
     * @see \Log\Api\Adapter\LogAdapter::buildQueryDateComparison()
     *
     * @todo Normalize with NumericDataTypes.
     */
    protected function buildQueryDateComparison(QueryBuilder $qb, array $query, $value, $column): void
    {
        // TODO Format the date into a standard mysql datetime.
        $matches = [];
        preg_match('/^[^\d]+/', $value, $matches);
        if (!empty($matches[0])) {
            $operators = [
                '>=' => Comparison::GTE,
                '>' => Comparison::GT,
                '<' => Comparison::LT,
                '<=' => Comparison::LTE,
                '<>' => Comparison::NEQ,
                '=' => Comparison::EQ,
                'gte' => Comparison::GTE,
                'gt' => Comparison::GT,
                'lt' => Comparison::LT,
                'lte' => Comparison::LTE,
                'neq' => Comparison::NEQ,
                'eq' => Comparison::EQ,
                'ex' => 'IS NOT NULL',
                'nex' => 'IS NULL',
            ];
            $operator = trim($matches[0]);
            $operator = $operators[$operator] ?? Comparison::EQ;
            $value = mb_substr($value, mb_strlen($matches[0]));
        } else {
            $operator = Comparison::EQ;
        }
        $value = trim($value);

        // By default, sql replace missing time by 00:00:00, but this is not
        // clear for the user. And it doesn't allow partial date/time.
        // See module Advanced Search Plus.

        $expr = $qb->expr();

        // $qb->andWhere(new Comparison(
        //     $alias . '.' . $column,
        //     $operator,
        //     $this->createNamedParameter($qb, $value)
        // ));
        // return;

        $field = 'omeka_root.' . $column;
        switch ($operator) {
            case Comparison::GT:
                if (mb_strlen($value) < 19) {
                    // TODO Manage mb for substr_replace.
                    $value = substr_replace('9999-12-31 23:59:59', $value, 0, mb_strlen($value) - 19);
                }
                $param = $this->createNamedParameter($qb, $value);
                $predicateExpr = $expr->gt($field, $param);
                break;
            case Comparison::GTE:
                if (mb_strlen($value) < 19) {
                    $value = substr_replace('0000-01-01 00:00:00', $value, 0, mb_strlen($value) - 19);
                }
                $param = $this->createNamedParameter($qb, $value);
                $predicateExpr = $expr->gte($field, $param);
                break;
            case Comparison::EQ:
                if (mb_strlen($value) < 19) {
                    $valueFrom = substr_replace('0000-01-01 00:00:00', $value, 0, mb_strlen($value) - 19);
                    $valueTo = substr_replace('9999-12-31 23:59:59', $value, 0, mb_strlen($value) - 19);
                    $paramFrom = $this->createNamedParameter($qb, $valueFrom);
                    $paramTo = $this->createNamedParameter($qb, $valueTo);
                    $predicateExpr = $expr->between($field, $paramFrom, $paramTo);
                } else {
                    $param = $this->createNamedParameter($qb, $value);
                    $predicateExpr = $expr->eq($field, $param);
                }
                break;
            case Comparison::NEQ:
                if (mb_strlen($value) < 19) {
                    $valueFrom = substr_replace('0000-01-01 00:00:00', $value, 0, mb_strlen($value) - 19);
                    $valueTo = substr_replace('9999-12-31 23:59:59', $value, 0, mb_strlen($value) - 19);
                    $paramFrom = $this->createNamedParameter($qb, $valueFrom);
                    $paramTo = $this->createNamedParameter($qb, $valueTo);
                    $predicateExpr = $expr->not(
                        $expr->between($field, $paramFrom, $paramTo)
                    );
                } else {
                    $param = $this->createNamedParameter($qb, $value);
                    $predicateExpr = $expr->neq($field, $param);
                }
                break;
            case Comparison::LTE:
                if (mb_strlen($value) < 19) {
                    $value = substr_replace('9999-12-31 23:59:59', $value, 0, mb_strlen($value) - 19);
                }
                $param = $this->createNamedParameter($qb, $value);
                $predicateExpr = $expr->lte($field, $param);
                break;
            case Comparison::LT:
                if (mb_strlen($value) < 19) {
                    $value = substr_replace('0000-01-01 00:00:00', $value, 0, mb_strlen($value) - 19);
                }
                $param = $this->createNamedParameter($qb, $value);
                $predicateExpr = $expr->lt($field, $param);
                break;
            case 'IS NOT NULL':
                $predicateExpr = $expr->isNotNull($field);
                break;
            case 'IS NULL':
                $predicateExpr = $expr->isNull($field);
                break;
            default:
                return;
        }

        $qb->andWhere($predicateExpr);
    }
}

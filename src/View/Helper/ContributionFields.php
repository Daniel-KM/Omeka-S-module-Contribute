<?php declare(strict_types=1);

namespace Contribute\View\Helper;

use Contribute\Api\Representation\ContributionRepresentation;
use Contribute\Mvc\Controller\Plugin\ContributiveData;
use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ResourceTemplateRepresentation;

class ContributionFields extends AbstractHelper
{
    /**
     * @var array
     */
    protected $propertiesByTerm;

    /**
     * @var ContributiveData
     */
    protected $contributiveData;

    /**
     * @var bool
     */
    protected $hasAdvancedTemplate;

    /**
     * @var bool
     */
    protected $hasNumericDataTypes;

    /**
     * @var bool
     */
    protected $hasValueSuggest;

    /**
     * @var ?array
     */
    protected $customVocabBaseTypes;

    public function __construct(
        array $propertiesByTerm,
        ContributiveData $contributiveData,
        bool $hasAdvancedTemplate,
        bool $hasNumericDataTypes,
        bool $hasValueSuggest,
        ?array $customVocabBaseTypes
    ) {
        $this->propertiesByTerm = $propertiesByTerm;
        $this->contributiveData = $contributiveData;
        $this->hasAdvancedTemplate = $hasAdvancedTemplate;
        $this->hasNumericDataTypes = $hasNumericDataTypes;
        $this->hasValueSuggest = $hasValueSuggest;
        $this->customVocabBaseTypes = $customVocabBaseTypes;
    }

    /**
     * Get all fields for this resource, updatable or not.
     *
     * The order is the one of the resource template.
     *
     * Some contributions may not have the matching fields: it means that the
     * config changed, so the values are no more editable, so they are skipped.
     *
     * The output is similar than $resource->values(), but may contain empty
     * properties, and four more keys, editable, fillable, data types and
     * contributions.
     *
     * Note that sub-contribution fields for media are not included here.
     *
     * The minimum number of contributions is managed: empty contributions may
     * be added according to the minimal number of values.
     *
     * <code>
     * [
     *   {term} => [
     *     'template_property' => {ResourceTemplatePropertyRepresentation},
     *     'property' => {PropertyRepresentation},
     *     'alternate_label' => {label},
     *     'alternate_comment' => {comment},
     *     'required' => {bool},
     *     'min_values' => {int},
     *     'max_values' => {int},
     *     'more_values' => {int},
     *     'editable' => {bool},
     *     'fillable' => {bool},
     *     'datatypes' => {array},
     *     'values' => [
     *       {ValueRepresentation}, …
     *     ],
     *     'contributions' => [
     *       [
     *         'type' => {string},
     *         'basetype' => {string}, // To make process easier (literal, resource or uri).
     *         'new' => {bool}, // Is a new value (edited/filled by user or missing value).
     *         'empty' => {bool}, // No contribution or removed value.
     *         'original' => [
     *           'value' => {ValueRepresentation},
     *           '@value' => {string},
     *           '@resource' => {int},
     *           '@uri' => {string},
     *           '@label' => {string},
     *         ],
     *         'proposed' => [
     *           'store' => {string}, // Path where a file is stored (for media only).
     *           '@value' => {string},
     *           '@resource' => {int},
     *           '@uri' => {string},
     *           '@label' => {string},
     *         ],
     *       ], …
     *     ],
     *   ],
     * ]
     * </code>
     *
     * @todo Remove the "@" in proposition values (or build a class).
     * @todo Store language.
     *
     * @todo Factorize with \Contribute\Site\ContributionController::prepareProposal()
     * @todo Factorize with \Contribute\Api\Representation\ContributionRepresentation::proposalNormalizeForValidation()
     * @todo Factorize with \Contribute\Api\Representation\ContributionRepresentation::proposalToResourceData()
     *
     * @todo Simplify when the status "is patch" or "new resource" (at least remove all original data).
     *
     * @var bool $isSubTemplate Allow to check the good allowed template via
     *   contributiveData(), so the allowed resource templates or allowed
     *   resource templages for media). No other difference, so invoke the right
     *   resource, the right contribution part, or the right template when
     *   needed.
     */
    public function __invoke(
        ?AbstractResourceEntityRepresentation $resource = null,
        ?ContributionRepresentation $contribution = null,
        ?ResourceTemplateRepresentation $resourceTemplate = null,
        ?bool $isSubTemplate = false,
        ?int $indexProposalMedia = null
    ): array {
        $fields = [];

        $isSubTemplate = (bool) $isSubTemplate;
        $defaultField = [
            'template_property' => null,
            'property' => null,
            'alternate_label' => null,
            'alternate_comment' => null,
            'required' => false,
            'min_values' => 0,
            'max_values' => 0,
            'more_values' => false,
            'editable' => false,
            'fillable' => false,
            'datatypes' => [],
            'values' => [],
            'contributions' => [],
        ];

        // The contribution is always on the stored resource, if any.
        $values = [];
        if ($contribution) {
            $resource = $contribution->resource();
            if ($resource) {
                $values = $resource->values();
                if (!$isSubTemplate) {
                    $resourceTemplate = $resource->resourceTemplate();
                }
            }
            if (!$isSubTemplate) {
                $resourceTemplate = $contribution->resourceTemplate();
            }
        } elseif ($resource) {
            $resourceTemplate = $resource->resourceTemplate();
            $values = $resource->values();
        }

        $contributive = clone $this->contributiveData;
        $contributive = $contributive->__invoke($resourceTemplate, $isSubTemplate);
        $resourceTemplate = $contributive->template();

        // TODO Currently, only new media are managed as sub-resource: contribution for new resource, not contribution for existing item with media at the same time.
        if ($isSubTemplate) {
            $values = [];
        }

        $customVocabBaseTypes = $this->getView()->plugin('customVocabBaseType')();

        // List the fields for the resource.
        foreach ($resourceTemplate ? $resourceTemplate->resourceTemplateProperties() : [] as $templateProperty) {
            $property = $templateProperty->property();
            $term = $property->term();
            if ($this->hasAdvancedTemplate) {
                /** @var \AdvancedResourceTemplate\Api\Representation\ResourceTemplatePropertyRepresentation $templateProperty */
                $minValues = (int) $templateProperty->mainDataValue('min_values');
                $maxValues = (int) $templateProperty->mainDataValue('max_values');
            } else {
                $minValues = 0;
                $maxValues = 0;
            }
            $valuesValues = $values[$term]['values'] ?? [];
            $fields[$term] = [
                'template_property' => $templateProperty,
                'property' => $property,
                'alternate_label' => $templateProperty->alternateLabel(),
                'alternate_comment' => $templateProperty->alternateComment(),
                'required' => $templateProperty->isRequired(),
                'min_values' => $minValues,
                'max_values' => $maxValues,
                'more_values' => $maxValues && count($valuesValues) < $maxValues,
                'editable' => $contributive->isTermEditable($term),
                'fillable' => $contributive->isTermFillable($term),
                'datatypes' => $contributive->dataTypeTerm($term),
                'values' => $valuesValues,
                'contributions' => [],
            ];
        }

        // The remaining values don't have a template and are never editable.
        foreach ($values as $term => $valueInfo) {
            if (!isset($fields[$term])) {
                // Value info includes the property and the values.
                $fields[$term] = $valueInfo;
                $fields[$term]['required'] = false;
                $fields[$term]['min_values'] = 0;
                $fields[$term]['max_values'] = 0;
                $fields[$term]['more_values'] = false;
                $fields[$term]['editable'] = false;
                $fields[$term]['fillable'] = false;
                $fields[$term]['datatypes'] = [];
                $fields[$term]['contributions'] = [];
                $fields[$term] = array_replace($defaultField, $fields[$term]);
            }
        }

        // The template is required.
        if (!$resourceTemplate || !$contributive || !$contributive->isContributive()) {
            return $fields;
        }

        // Initialize contributions with existing values, then append contributions.

        foreach ($fields as $term => $field) {
            if ($term === 'file') {
                continue;
            }
            /** @var \Omeka\Api\Representation\ValueRepresentation $value */
            foreach ($field['values'] as $value) {
                // Method value() is label or value depending on type.
                $type = $value->type();
                $typeColon = strtok($type, ':');
                $baseType = $typeColon === 'customvocab'
                    ? $customVocabBaseTypes[(int) substr($type, 12)] ?? 'literal'
                    : null;
                // TODO No need to check if the data type is managed?
                if (in_array($typeColon, ['uri', 'valuesuggest', 'valuesuggestall'])
                    || ($typeColon === 'customvocab' && $baseType === 'uri')
                ) {
                    $baseType = 'uri';
                    $val = null;
                    $res = null;
                    $uri = $value->uri();
                    $label = $value->value();
                } elseif ($typeColon === 'resource'
                    || ($typeColon === 'customvocab' && $baseType === 'resource')
                ) {
                    $baseType = 'resource';
                    $vr = $value->valueResource();
                    $val = null;
                    $res = $vr ? $vr->id() : null;
                    $uri = null;
                    $label = null;
                } else {
                    $baseType = 'literal';
                    $val = $value->value();
                    $res = null;
                    $uri = null;
                    $label = null;
                }
                $fields[$term]['contributions'][] = [
                    // The type cannot be changed.
                    'type' => $type,
                    'basetype' => $baseType,
                    'new' => false,
                    'empty' => true,
                    'original' => [
                        'value' => $value,
                        '@value' => $val,
                        '@resource' => $res,
                        '@uri' => $uri,
                        '@label' => $label,
                    ],
                    'proposed' => [
                        '@value' => null,
                        '@resource' => null,
                        '@uri' => null,
                        '@label' => null,
                    ],
                ];
            }
        }

        if (!$contribution) {
            return $this->finalize($fields);
        }

        $proposals = $contribution->proposal();
        if (is_int($indexProposalMedia)) {
            $proposals = $proposals['media'][$indexProposalMedia] ?? [];
        }

        // Clean data for the special keys.
        unset($proposals['template'], $proposals['media']);

        foreach ($proposals as $term => $termProposal) {
            if (!is_array($termProposal)) {
                // Data "migrated" = true can be stored here.
                continue;
            }
            foreach ($termProposal as $key => $proposal) {
                if (isset($proposal['proposed']['@uri'])) {
                    $proposal['original']['@uri'] = $this->cleanString($proposal['original']['@uri']);
                    $proposal['original']['@label'] = $this->cleanString($proposal['original']['@label']);
                    if (($proposal['original']['@uri'] === '' && $proposal['proposed']['@uri'] === '')
                        && ($proposal['original']['@label'] === '' && $proposal['proposed']['@label'] === '')
                    ) {
                        unset($proposals[$term][$key]);
                    }
                } elseif (isset($proposal['proposed']['@resource'])) {
                    $proposal['original']['@resource'] = (int) $proposal['original']['@resource'];
                    if (!$proposal['original']['@resource'] && !$proposal['proposed']['@resource']) {
                        unset($proposals[$term][$key]);
                    }
                } else {
                    $proposal['original']['@value'] = $this->cleanString($proposal['original']['@value']);
                    if ($proposal['original']['@value'] === '' && $proposal['proposed']['@value'] === '') {
                        unset($proposals[$term][$key]);
                    }
                }
            }
        }

        $proposals = array_filter($proposals);
        if (!count($proposals)) {
            return $this->finalize($fields);
        }

        // File is specific: for media only, one value only, not updatable,
        // not a property and not in resource template.
        if (isset($proposals['file'][0]['proposed']['@value']) && $proposals['file'][0]['proposed']['@value'] !== '') {
            // Fill the file first to keep it first.
            $fields = array_merge(['file' => []], $fields);
            $fields['file'] = [
                'template_property' => null,
                'property' => null,
                'alternate_label' => $this->getView()->translate('File'),
                'alternate_comment' => null,
                'required' => true,
                'min_values' => 1,
                'max_values' => 1,
                'more_values' => false,
                'editable' => false,
                'fillable' => true,
                'datatypes' => ['file'],
                'values' => [],
                'contributions' => [],
            ];
            $fields['file']['contributions'][] = [
                'type' => 'file',
                'basetype' => 'literal',
                'lang' => null,
                'new' => true,
                'empty' => empty($proposals['file'][0]['proposed']['store']),
                'original' => [
                    'value' => null,
                    '@resource' => null,
                    '@value' => null,
                    '@uri' => null,
                    '@label' => null,
                ],
                'proposed' => [
                    'store' => $proposals['file'][0]['proposed']['store'] ?? null,
                    '@value' => $proposals['file'][0]['proposed']['@value'],
                    '@resource' => null,
                    '@uri' => null,
                    '@label' => null,
                ],
            ];
        }

        // Fill the proposed contributions, according to the original value.
        foreach ($fields as $term => &$field) {
            if ($term === 'file') {
                continue;
            }
            if (!isset($proposals[$term])) {
                continue;
            }
            foreach ($field['contributions'] as &$fieldContribution) {
                $proposed = null;
                $type = $fieldContribution['type'];
                if (!$contributive->isTermDataType($term, $type)) {
                    continue;
                }
                $typeColon = strtok($type, ':');
                $baseType = $typeColon === 'customvocab'
                    ? $customVocabBaseTypes[(int) substr($type, 12)] ?? 'literal'
                    : null;
                if (in_array($typeColon, ['uri', 'valuesuggest', 'valuesuggestall'])
                    || ($typeColon === 'customvocab' && $baseType === 'uri')
                ) {
                    foreach ($proposals[$term] as $keyProposal => $proposal) {
                        // For the customvocab, the label is static, so use the
                        // original one, but here the label is already checked.
                        if (isset($proposal['original']['@uri'])
                            && $proposal['original']['@uri'] === $fieldContribution['original']['@uri']
                            && $proposal['original']['@label'] === $fieldContribution['original']['@label']
                        ) {
                            $proposed = $proposal['proposed'];
                            break;
                        }
                    }
                    if (is_null($proposed)) {
                        continue;
                    }
                    $fieldContribution['empty'] = false;
                    $fieldContribution['proposed'] = [
                        '@value' => null,
                        '@resource' => null,
                        '@uri' => $proposed['@uri'],
                        '@label' => $proposed['@label'],
                    ];
                } elseif ($typeColon === 'resource'
                    || ($typeColon === 'customvocab' && $baseType === 'resource')
                ) {
                    foreach ($proposals[$term] as $keyProposal => $proposal) {
                        if (isset($proposal['original']['@resource'])
                            && (int) $proposal['original']['@resource']
                            && $proposal['original']['@resource'] === $fieldContribution['original']['@resource']
                        ) {
                            $proposed = $proposal['proposed'];
                            break;
                        }
                    }
                    if (is_null($proposed)) {
                        continue;
                    }
                    $fieldContribution['empty'] = false;
                    $fieldContribution['proposed'] = [
                        '@value' => null,
                        '@resource' => (int) $proposed['@resource'],
                        '@uri' => null,
                        '@label' => null,
                    ];
                } else {
                    foreach ($proposals[$term] as $keyProposal => $proposal) {
                        if (isset($proposal['original']['@value'])
                            && $proposal['original']['@value'] === $fieldContribution['original']['@value']
                        ) {
                            $proposed = $proposal['proposed'];
                            break;
                        }
                    }
                    if (is_null($proposed)) {
                        continue;
                    }
                    $fieldContribution['empty'] = false;
                    $fieldContribution['proposed'] = [
                        '@value' => $proposed['@value'],
                        '@resource' => null,
                        '@uri' => null,
                        '@label' => null,
                    ];
                }
                unset($proposals[$term][$keyProposal]);
            }
        }
        unset($field, $fieldContribution);

        // Fill the proposed contribute, according to the existing values: some
        // contributions may have been accepted or the resource updated, so check
        // if there are remaining contributions that were validated.
        foreach ($fields as $term => &$field) {
            if ($term === 'file') {
                continue;
            }
            if (!isset($proposals[$term])) {
                continue;
            }
            foreach ($field['contributions'] as &$fieldContribution) {
                $proposed = null;
                $type = $fieldContribution['type'];
                if (!$contributive->isTermDatatype($term, $type)) {
                    continue;
                }
                $typeColon = strtok($type, ':');
                $baseType = $typeColon === 'customvocab'
                    ? $customVocabBaseTypes[(int) substr($type, 12)] ?? 'literal'
                    : null;
                if (in_array($typeColon, ['uri', 'valuesuggest', 'valuesuggestall'])
                    || ($typeColon === 'customvocab' && $baseType === 'uri')
                ) {
                    foreach ($proposals[$term] as $keyProposal => $proposal) {
                        if (isset($proposal['proposed']['@uri'])
                            && $proposal['proposed']['@uri'] === $fieldContribution['original']['@uri']
                            && $proposal['proposed']['@label'] === $fieldContribution['original']['@label']
                        ) {
                            $proposed = $proposal['proposed'];
                            break;
                        }
                    }
                    if (is_null($proposed)) {
                        continue;
                    }
                    $fieldContribution['empty'] = false;
                    $fieldContribution['proposed'] = [
                        '@value' => null,
                        '@resource' => null,
                        '@uri' => $proposed['@uri'],
                        '@label' => $proposed['@label'],
                    ];
                } elseif ($typeColon === 'resource'
                    || ($typeColon === 'customvocab' && $baseType === 'resource')
                ) {
                    foreach ($proposals[$term] as $keyProposal => $proposal) {
                        if (isset($proposal['proposed']['@resource'])
                            && (int) $proposal['proposed']['@resource']
                            && $proposal['proposed']['@resource'] === $fieldContribution['original']['@resource']
                        ) {
                            $proposed = $proposal['proposed'];
                            break;
                        }
                    }
                    if (is_null($proposed)) {
                        continue;
                    }
                    $fieldContribution['empty'] = false;
                    $fieldContribution['proposed'] = [
                        '@value' => null,
                        '@resource' => (int) $proposed['@resource'],
                        '@uri' => null,
                        '@label' => null,
                    ];
                } else {
                    foreach ($proposals[$term] as $keyProposal => $proposal) {
                        if (isset($proposal['proposed']['@value'])
                            && $proposal['proposed']['@value'] === $fieldContribution['original']['@value']
                        ) {
                            $proposed = $proposal['proposed'];
                            break;
                        }
                    }
                    if (is_null($proposed)) {
                        continue;
                    }
                    $fieldContribution['empty'] = false;
                    $fieldContribution['proposed'] = [
                        '@value' => $proposed['@value'],
                        '@resource' => null,
                        '@uri' => null,
                        '@label' => null,
                    ];
                }
                unset($proposals[$term][$keyProposal]);
            }
        }
        unset($field, $fieldContribution);

        // Append only remaining contributions that are fillable.
        // Other ones are related to an older config.
        $proposals = array_intersect_key(array_filter($proposals), $contributive->fillableProperties());
        foreach ($proposals as $term => $termProposal) {
            if (!isset($this->propertiesByTerm[$term])) {
                continue;
            }
            $propertyId = $this->propertiesByTerm[$term];
            $typeTemplate = null;
            $resourceTemplateProperty = $resourceTemplate
                ? $resourceTemplate->resourceTemplateProperty($propertyId)
                : null;
            // TODO Check if it is possible to have a property that is not set.
            if ($resourceTemplateProperty) {
                $typeTemplate = $resourceTemplateProperty->dataType();
            }
            foreach ($termProposal as $proposal) {
                if (!empty($proposal['empty'])) {
                    continue;
                }
                if ($typeTemplate) {
                    $type = $typeTemplate;
                } elseif (isset($proposal['proposed']['@uri'])) {
                    $type = 'uri';
                } elseif (isset($proposal['proposed']['@resource'])) {
                    $type = 'resource';
                } else {
                    $type = 'literal';
                }
                if (!$contributive->isTermDatatype($term, $type)) {
                    continue;
                }
                $typeColon = strtok($type, ':');
                $baseType = null;
                if ($typeColon === 'customvocab') {
                    $customVocabId = (int) substr($type, 12);
                    $baseType = $customVocabBaseTypes[$customVocabId] ?? 'literal';
                }
                if (in_array($typeColon, ['uri', 'valuesuggest', 'valuesuggestall'])
                    || ($typeColon === 'customvocab' && $baseType === 'uri')
                ) {
                    $fields[$term]['contributions'][] = [
                        'type' => $type,
                        'basetype' => 'uri',
                        'new' => true,
                        'empty' => false,
                        'original' => [
                            'value' => null,
                            '@resource' => null,
                            '@value' => null,
                            '@uri' => null,
                            '@label' => null,
                        ],
                        'proposed' => [
                            '@value' => null,
                            '@resource' => null,
                            '@uri' => $proposal['proposed']['@uri'] ?? '',
                            '@label' => $proposal['proposed']['@label'] ?? '',
                        ],
                    ];
                } elseif ($typeColon === 'resource'
                    || ($typeColon === 'customvocab' && $baseType === 'resource')
                ) {
                    $fields[$term]['contributions'][] = [
                        'type' => $type,
                        'basetype' => 'resource',
                        'new' => true,
                        'empty' => false,
                        'original' => [
                            'value' => null,
                            '@resource' => null,
                            '@value' => null,
                            '@uri' => null,
                            '@label' => null,
                        ],
                        'proposed' => [
                            '@value' => null,
                            '@resource' => (int) ($proposal['proposed']['@resource'] ?? 0),
                            '@uri' => null,
                            '@label' => null,
                        ],
                    ];
                } else {
                    $fields[$term]['contributions'][] = [
                        'type' => $type,
                        'basetype' => 'literal',
                        'new' => true,
                        'empty' => false,
                        'original' => [
                            'value' => null,
                            '@resource' => null,
                            '@value' => null,
                            '@uri' => null,
                            '@label' => null,
                        ],
                        'proposed' => [
                            '@value' => $proposal['proposed']['@value'] ?? '',
                            '@resource' => null,
                            '@uri' => null,
                            '@label' => null,
                        ],
                    ];
                }
            }
        }

        return $this->finalize($fields);
    }

    /**
     * Finalize: remove invalid contributions and add empty ones when needed.
     */
    protected function finalize(array $fields): array
    {
        foreach ($fields as $term => &$field) {
            if ($term === 'file') {
                continue;
            }
            // Remove contributions with an invalid or an unavailable type.
            // This is a security fix, but it can remove data.
            foreach ($field['contributions'] as $key => &$fieldContribution) {
                $type = $fieldContribution['type'] ?? '';
                $typeColon = strtok($type, ':');
                $baseType = $this->baseType($type);
                // FIXME Warning, numeric:interval and numeric:duration are not managed.
                if (!$this->hasNumericDataTypes && $typeColon === 'numeric') {
                    unset($field['contributions'][$key]);
                    continue;
                }
                if (!$this->customVocabBaseTypes && $typeColon === 'customvocab') {
                    unset($field['contributions'][$key]);
                    continue;
                }
                if (!$this->hasValueSuggest && ($typeColon === 'valuesuggest' || $typeColon === 'valuesuggestall')) {
                    unset($field['contributions'][$key]);
                    continue;
                }
            }
            unset($fieldContribution);

            // Clean indexes for old contributions.
            $field['contributions'] = array_values($field['contributions']);
            if (!$field['fillable']) {
                continue;
            }

            // The minimum is 1 when a value is required.
            $minValues = (int) $field['min_values'] ?: (int) $field['required'];
            $maxValues = (int) $field['max_values'];
            if (!$minValues && !$maxValues) {
                $field['more_values'] = true;
                continue;
            }

            // If editable, values and contributions are a single list, else
            // they are combined.
            // TODO Check for correction, with some values corrected and some appended.
            $countValues = count($field['values']);
            $countContributions = count($field['contributions']);
            $countExisting = $field['editable']
                ? max($countValues, $countContributions)
                : $countValues + $countContributions;
            $missingValues = $minValues && $minValues > $countExisting
                ? $minValues - $countExisting
                : 0;
            // The button is always added, and managed by js anyway, because the
            // button should be available when a value is removed.
            $field['more_values'] = !$maxValues
                || $maxValues < $missingValues;

            $type = reset($field['datatypes']);
            $baseType = $this->baseType($type);
            // Prepare empty contributions to simplify theme.
            while ($missingValues) {
                $field['contributions'][] = [
                    'type' => $type,
                    'basetype' => $baseType,
                    'new' => true,
                    'empty' => true,
                    'original' => [
                        'value' => null,
                        '@value' => null,
                    ],
                    'proposed' => [
                        '@value' => null,
                    ],
                ];
                --$missingValues;
            }
        }
        return $fields;
    }

    protected function baseType(string $type): ?string
    {
        static $customVocabBaseTypes;
        static $baseTypes = [
            'literal' => 'literal',
            'numeric' => 'literal',
            'resource' => 'resource',
            'resource:item' => 'resource',
            'resource:itemset' => 'resource',
            'resoufce:media' => 'resource',
            'uri' => 'uri',
            'valuesuggest' => 'uri',
            'valuesuggestall' => 'uri',
        ];

        if (!isset($baseTypes[$type])) {
            $customVocabBaseTypes = $this->getView()->plugin('customVocabBaseType')();
            $typeColon = strtok($type, ':');
            if (isset($baseTypes[$typeColon])) {
                return $baseTypes[$typeColon];
            }
            // The only other case is customvocab.
            if ($typeColon === 'customvocab') {
                $baseTypes[$typeColon] = $customVocabBaseTypes[(int) substr($type, 12)] ?? 'literal';
            }
        }
        return $baseTypes[$type] ?? 'literal';
    }

    /**
     * Trim and normalize end of lines of a string.
     */
    protected function cleanString($string): string
    {
        return str_replace(["\r\n", "\n\r", "\r"], ["\n", "\n", "\n"], trim((string) $string));
    }
}

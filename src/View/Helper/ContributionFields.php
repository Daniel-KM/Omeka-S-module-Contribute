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

    public function __construct(
        array $propertiesByTerm,
        ContributiveData $contributiveData,
        bool $hasAdvancedTemplate
    ) {
        $this->propertiesByTerm = $propertiesByTerm;
        $this->contributiveData = $contributiveData;
        $this->hasAdvancedTemplate = $hasAdvancedTemplate;
    }

    /**
     * Get all fields that are updatable for this resource.
     *
     * The order is the one of the resource template, else the order of terms in
     * the database (Dublin Core first, bibo, foaf, then specific terms).
     *
     * Some contributions may not have the matching fields: it means that the
     * config changed, so the values are no more editable, so they are skipped.
     *
     * The output is similar than $resource->values(), but may contain empty
     * properties, and four more keys, editable, fillable, datatypes and
     * contributions.
     *
     * <code>
     * [
     *   {term} => [
     *     'template_property' => {ResourceTemplatePropertyRepresentation},
     *     'property' => {PropertyRepresentation},
     *     'alternate_label' => {label},
     *     'alternate_comment' => {comment},
     *     'required' => {bool},
     *     'max_values' => {int},
     *     'editable' => {bool},
     *     'fillable' => {bool},
     *     'datatypes' => {array},
     *     'values' => [
     *       {ValueRepresentation}, …
     *     ],
     *     'contributions' => [
     *       [
     *         'type' => {string},
     *         'original' => [
     *           'value' => {ValueRepresentation},
     *           '@value' => {string},
     *           '@resource' => {int},
     *           '@uri' => {string},
     *           '@label' => {string},
     *         ],
     *         'proposed' => [
     *           '@value' => {string},
     *           '@resource' => {int}
     *           '@uri' => {string},
     *           '@label' => {string},
     *         ],
     *       ], …
     *     ],
     *   ],
     * ]
     * </code>
     */
    public function __invoke(
        ?AbstractResourceEntityRepresentation $resource = null,
        ?ContributionRepresentation $contribution = null,
        ?ResourceTemplateRepresentation $resourceTemplate = null
    ): array {
        $fields = [];

        $defaultField = [
            'template_property' => null,
            'property' => null,
            'alternate_label' => null,
            'alternate_comment' => null,
            'required' => false,
            'max_values' => 0,
            'editable' => false,
            'fillable' => false,
            'datatypes' => [],
            'values' => [],
            'contributions' => [],
        ];

        if ($resource) {
            $resourceTemplate = $resource->resourceTemplate();
            $values = $resource->values();
        } else {
            $values = [];
        }
        $contributive = $this->contributiveData->__invoke($resourceTemplate);

        if (!$contributive->hasTemplate()) {
            return [];
        }

        // List the fields for the resource.
        foreach ($contributive->template()->resourceTemplateProperties() as $templateProperty) {
            $property = $templateProperty->property();
            $term = $property->term();
            if ($this->hasAdvancedTemplate) {
                $rtpDatas = $templateProperty->data();
                if (count($rtpDatas)) {
                    $rtpData = reset($rtpDatas);
                    $maxValues = (int) $rtpData->dataValue('max_values', 0);
                }
            }
            $fields[$term] = [
                'template_property' => $templateProperty,
                'property' => $property,
                'alternate_label' => $templateProperty->alternateLabel(),
                'alternate_comment' => $templateProperty->alternateComment(),
                'required' => $templateProperty->isRequired(),
                'max_values' => empty($maxValues) ? 0 : (int) $maxValues,
                'editable' => $contributive->isTermEditable($term),
                'fillable' => $contributive->isTermFillable($term),
                'datatypes' => $contributive->datatypeTerm($term),
                'values' => $values[$term]['values'] ?? [],
                'contributions' => [],
            ];
        }

        // The remaining values are never editable.
        foreach ($values as $term => $valueInfo) {
            if (!isset($fields[$term])) {
                $fields[$term] = $valueInfo;
                $fields[$term]['required'] = false;
                $fields[$term]['max_values'] = 0;
                $fields[$term]['editable'] = false;
                $fields[$term]['fillable'] = false;
                $fields[$term]['datatypes'] = [];
                $fields[$term]['contributions'] = [];
                $fields[$term] = array_replace($defaultField, $fields[$term]);
            }
        }

        // Initialize contributions with existing values, then append contributions.
        foreach ($fields as $term => $field) {
            /** @var \Omeka\Api\Representation\ValueRepresentation $value */
            foreach ($field['values'] as $value) {
                // Method value() is label or value depending on type.
                $type = $value->type();
                // TODO No need to check if the datatype is managed?
                if ($type === 'uri' || in_array(strtok($type, ':'), ['valuesuggest', 'valuesuggestall'])) {
                    $val = null;
                    $res = null;
                    $uri = $value->uri();
                    $label = $value->value();
                } elseif (strtok($type, ':') === 'resource') {
                    $vr = $value->valueResource();
                    $val = null;
                    $res = $vr ? $vr->id() : null;
                    $uri = null;
                    $label = null;
                } else {
                    $val = $value->value();
                    $res = null;
                    $uri = null;
                    $label = null;
                }
                $fields[$term]['contributions'][] = [
                    // The type cannot be changed.
                    'type' => $type,
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
            return $fields;
        }

        $proposals = $contribution->proposal();

        // Clean old proposals.
        foreach ($proposals as $term => $termProposal) {
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
            return $fields;
        }

        // Fill the proposed contributions, according to the original value.
        foreach ($fields as $term => &$field) {
            if (!isset($proposals[$term])) {
                continue;
            }
            foreach ($field['contributions'] as &$fieldContribution) {
                $proposed = null;
                $type = $fieldContribution['type'];
                if (!$contributive->isTermDatatype($term, $type)) {
                    continue;
                }
                if ($type === 'uri' || in_array(strtok($type, ':'), ['valuesuggest', 'valuesuggestall'])) {
                    foreach ($proposals[$term] as $keyProposal => $proposal) {
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
                    $fieldContribution['proposed'] = [
                        '@value' => null,
                        '@resource' => null,
                        '@uri' => $proposed['@uri'],
                        '@label' => $proposed['@label'],
                    ];
                } elseif (strtok($type, ':') === 'resource') {
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
            if (!isset($proposals[$term])) {
                continue;
            }
            foreach ($field['contributions'] as &$fieldContribution) {
                $proposed = null;
                $type = $fieldContribution['type'];
                if (!$contributive->isTermDatatype($term, $type)) {
                    continue;
                }
                if ($type === 'uri' || in_array(strtok($type, ':'), ['valuesuggest', 'valuesuggestall'])) {
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
                    $fieldContribution['proposed'] = [
                        '@value' => null,
                        '@resource' => null,
                        '@uri' => $proposed['@uri'],
                        '@label' => $proposed['@label'],
                    ];
                } elseif (strtok($type, ':') === 'resource') {
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
            $resourceTemplateProperty = $resourceTemplate->resourceTemplateProperty($propertyId);
            // TODO Check if it is possible to have a property that is not set.
            if ($resourceTemplateProperty) {
                $typeTemplate = $resourceTemplateProperty->dataType();
            }
            foreach ($termProposal as $proposal) {
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
                if ($type === 'uri' || in_array(strtok($type, ':'), ['valuesuggest', 'valuesuggestall'])) {
                    $fields[$term]['contributions'][] = [
                        'type' => $type,
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
                            '@uri' => $proposal['proposed']['@uri'],
                            '@label' => $proposal['proposed']['@label'],
                        ],
                    ];
                } elseif (strtok($type, ':') === 'resource') {
                    $fields[$term]['contributions'][] = [
                        'type' => $type,
                        'original' => [
                            'value' => null,
                            '@resource' => null,
                            '@value' => null,
                            '@uri' => null,
                            '@label' => null,
                        ],
                        'proposed' => [
                            '@value' => null,
                            '@resource' => (int) $proposal['proposed']['@resource'],
                            '@uri' => null,
                            '@label' => null,
                        ],
                    ];
                } else {
                    $fields[$term]['contributions'][] = [
                        'type' => 'literal',
                        'original' => [
                            'value' => null,
                            '@resource' => null,
                            '@value' => null,
                            '@uri' => null,
                            '@label' => null,
                        ],
                        'proposed' => [
                            '@value' => $proposal['proposed']['@value'],
                            '@resource' => null,
                            '@uri' => null,
                            '@label' => null,
                        ],
                    ];
                }
            }
        }

        return $fields;
    }

    /**
     * Trim and normalize end of lines of a string.
     *
     * @param string $string
     * @return string
     */
    protected function cleanString($string): string
    {
        return str_replace(["\r\n", "\n\r", "\r"], ["\n", "\n", "\n"], trim((string) $string));
    }
}

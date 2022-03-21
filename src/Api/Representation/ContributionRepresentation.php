<?php declare(strict_types=1);

namespace Contribute\Api\Representation;

use DateTime;
use Omeka\Api\Exception;
use Omeka\Api\Representation\AbstractEntityRepresentation;
use Omeka\Api\Representation\ResourceTemplateRepresentation;

class ContributionRepresentation extends AbstractEntityRepresentation
{
    /**
     * @var array
     */
    protected $values;

    /**
     * @var array
     */
    protected $valuesMedias;

    /**
     * Get the resource name of the corresponding entity API adapter.
     */
    public function resourceName(): string
    {
        return 'contributions';
    }

    public function getControllerName()
    {
        return 'contribution';
    }

    public function getJsonLdType()
    {
        return 'o-module-contribute:Contribution';
    }

    public function getJsonLd()
    {
        $token = $this->token();
        if ($token) {
            $token = $token->getReference();
        }

        $created = [
            '@value' => $this->getDateTime($this->created()),
            '@type' => 'http://www.w3.org/2001/XMLSchema#dateTime',
        ];

        $modified = $this->modified();
        if ($modified) {
            $modified = [
                '@value' => $this->getDateTime($modified),
                '@type' => 'http://www.w3.org/2001/XMLSchema#dateTime',
            ];
        }

        $contributionResource = $this->resource();
        $owner = $this->owner();

        return [
            'o:id' => $this->id(),
            'o:resource' => $contributionResource ? $contributionResource->getReference() : null,
            'o:owner' => $owner ? $owner->getReference() : null,
            'o:email' => $owner ? null : $this->email(),
            'o-module-contribute:patch' => $this->isPatch(),
            'o-module-contribute:submitted' => $this->isSubmitted(),
            'o-module-contribute:reviewed' => $this->isReviewed(),
            'o-module-contribute:proposal' => $this->proposal(),
            'o-module-contribute:token' => $token,
            'o:created' => $created,
            'o:modified' => $modified,
        ];
    }

    /**
     * @return \Omeka\Api\Representation\AbstractResourceEntityRepresentation|null
     */
    public function resource()
    {
        $contributionResource = $this->resource->getResource();
        return $contributionResource
            ? $this->getAdapter('resources')->getRepresentation($contributionResource)
            : null;
    }

    public function owner(): ?\Omeka\Api\Representation\UserRepresentation
    {
        $owner = $this->resource->getOwner();
        return $owner
            ? $this->getAdapter('users')->getRepresentation($owner)
            : null;
    }

    public function email(): ?string
    {
        return $this->resource->getEmail();
    }

    public function isPatch(): bool
    {
        return $this->resource->getPatch();
    }

    public function isSubmitted(): bool
    {
        return $this->resource->getSubmitted();
    }

    public function isReviewed(): bool
    {
        return $this->resource->getReviewed();
    }

    public function proposal(): array
    {
        return $this->resource->getProposal();
    }

    /**
     * Get all media proposals of this contribution.
     *
     * This is a shortcut to the key "media" of the proposal.
     */
    public function proposalMedias(): array
    {
        return $this->resource->getProposal()['media'] ?? [];
    }

    /**
     * The resource template is the resource one once submitted or when
     * correcting, else the one proposed by the user.
     */
    public function resourceTemplate(): ?ResourceTemplateRepresentation
    {
        $contributionResource = $this->resource();
        if ($contributionResource) {
            $resourceTemplate = $contributionResource->resourceTemplate();
        }
        if (empty($resourceTemplate)) {
            $proposal = $this->resource->getProposal();
            $resourceTemplate = $proposal['template'] ?? null;
            if ($resourceTemplate) {
                $templateAdapter = $this->getAdapter('resource_templates');
                try {
                    $resourceTemplate = $templateAdapter->findEntity(['id' => $resourceTemplate]);
                    $resourceTemplate = $templateAdapter->getRepresentation($resourceTemplate);
                } catch (Exception\NotFoundException $e) {
                    $resourceTemplate = null;
                }
            }
        }
        return $resourceTemplate;
    }

    public function token(): ?\Contribute\Api\Representation\TokenRepresentation
    {
        $token = $this->resource->getToken();
        return $token
            ? $this->getAdapter('contribution_tokens')->getRepresentation($token)
            : null;
    }

    public function created(): DateTime
    {
        return $this->resource->getCreated();
    }

    public function modified(): ?DateTime
    {
        return $this->resource->getModified();
    }

    /**
     * Get all proposed contributions for a term.
     */
    public function proposedValues(string $term): array
    {
        $data = $this->proposal();
        return empty($data[$term])
            ? []
            : $data[$term];
    }

    /**
     * Get a specific proposed contribution for a term.
     *
     * @return array|null Empty string value is used when the value is removed.
     */
    public function proposedValue(string $term, string $original): ?array
    {
        $proposed = $this->proposedValues($term);
        if (empty($proposed)) {
            return null;
        }
        foreach ($proposed as $value) {
            if (isset($value['original']['@value'])
                && $value['original']['@value'] === $original
            ) {
                return $value['proposed'];
            }
        }
        return null;
    }

    /**
     * Get a specific proposed contribution uri for a term.
     *
     * @return array|null Empty string uri is used when the value is removed.
     */
    public function proposedUriValue(string $term, string $originalUri, string $originalLabel): ?array
    {
        $proposed = $this->proposedValues($term);
        if (empty($proposed)) {
            return null;
        }
        foreach ($proposed as $value) {
            if (isset($value['original']['@uri'])
                && $value['original']['@uri'] === $originalUri
                && $value['original']['@label'] === $originalLabel
            ) {
                return $value['proposed'];
            }
        }
        return null;
    }

    /**
     * Check if a value is the same than the resource one.
     *
     * @return bool|null Null means no value, false if edited, true if
     * approved.
     */
    public function isApprovedValue(string $term, string $original): ?bool
    {
        $proposed = $this->proposedValues($term);
        if (empty($proposed)) {
            return null;
        }
        foreach ($proposed as $value) {
            if ($value['original']['@value'] === $original) {
                return $value['proposed']['@value'] === $value['original']['@value'];
            }
        }
        return null;
    }

    /**
     * Check if a value exists in original resource.
     */
    public function resourceValue(string $term, string $string): ?\Omeka\Api\Representation\ValueRepresentation
    {
        if ($string === '') {
            return null;
        }
        $contributionResource = $this->resource();
        if (!$contributionResource) {
            return null;
        }
        $values = $contributionResource->value($term, ['all' => true]);
        foreach ($values as $value) {
            if ((string) $value->value() === $string) {
                return $value;
            }
        }
        return null;
    }

    /**
     * Check if a resource value exists in original resource.
     */
    public function resourceValueResource(string $term, $intOrString): ?\Omeka\Api\Representation\ValueRepresentation
    {
        $int = (int) $intOrString;
        if (!$int) {
            return null;
        }
        $contributionResource = $this->resource();
        if (!$contributionResource) {
            return null;
        }
        $values = $contributionResource->value($term, ['all' => true]);
        $valueResource = null;
        foreach ($values as $value) {
            $type = $value->type();
            $typeColon = strtok($type, ':');
            if (in_array($typeColon, ['resource', 'customvocab'])
                && ($valueResource = $value->valueResource())
                && $valueResource->id() === $int
            ) {
                return $value;
            }
        }
        return null;
    }

    /**
     * Check if a uri exists in original resource.
     */
    public function resourceValueUri(string $term, string $string): ?\Omeka\Api\Representation\ValueRepresentation
    {
        if ($string === '') {
            return null;
        }
        $contributionResource = $this->resource();
        if (!$contributionResource) {
            return null;
        }
        // To get only uris and value suggest/custom vocab values require to get all values.
        $values = $contributionResource->value($term, ['all' => true]);
        foreach ($values as $value) {
            $type = $value->type();
            $typeColon = strtok($type, ':');
            if (in_array($typeColon, ['uri', 'valuesuggest', 'valuesuggestall', 'customvocab'])
                && $value->uri() === $string
            ) {
                return $value;
            }
        }
        return null;
    }

    /**
     * Check proposed contribution against current resource and normalize it.
     *
     * The sub-contributed medias are checked too via a recursive call.
     *
     * @todo Factorize with \Contribute\Admin\ContributionController::validateContribution()
     * @todo Factorize with \Contribute\Site\ContributionController::prepareProposal()
     * @todo Factorize with \Contribute\View\Helper\ContributionFields
     *
     * @todo Simplify when the status "is patch" or "new resource" (at least remove all original data).
     */
    public function proposalNormalizeForValidation(?int $indexProposalMedia = null): array
    {
        $contributive = $this->contributiveData();
        $proposal = $this->proposal();

        // Normalize sub-proposal.
        $isSubTemplate = is_int($indexProposalMedia);
        if ($isSubTemplate) {
            $contributive = $contributive->contributiveMedia();
            if (!$contributive) {
                return [];
            }
            $proposal = $proposal['media'][$indexProposalMedia] ?? [];
        }

        $services = $this->getServiceLocator();
        $propertyIds = $services->get('ControllerPluginManager')->get('propertyIdsByTerms')();
        $customVocabBaseTypes = $this->getViewHelper('customVocabBaseType')();

        // Use the resource template of the resource or the default one.
        $resourceTemplate = $contributive->template();

        // A template is required, but its check should be done somewhere else:
        // here, it's more about standardization of the proposal.
        // if (!$resourceTemplate) {
        //     return [];
        // }

        $medias = $proposal['media'] ?? [];
        $proposal['template'] = $resourceTemplate;
        $proposal['media'] = [];

        foreach ($proposal as $term => $propositions) {
            // Skip special keys.
            if ($term === 'template' || $term === 'media') {
                continue;
            }

            // File is specific: for media only, one value only, not updatable,
            // not a property and not in resource template.
            if ($term === 'file') {
                $prop = &$proposal[$term][0];
                $prop['value'] = null;
                $prop['value_updated'] = $prop['proposed']['@value'];
                $prop['validated'] = false;
                $prop['process'] = 'append';
                continue;
            }

            $isEditable = $contributive->isTermEditable($term);
            $isFillable = $contributive->isTermFillable($term);
            if (!$isEditable && !$isFillable) {
                // Skipped in the case options changed between contributions and moderation.
                // continue;
            }

            // In the case that the property was removed.
            if (!isset($propertyIds[$term])) {
                unset($proposal[$term]);
                continue;
            }

            $propertyId = $propertyIds[$term];
            $typeTemplate = null;
            if ($resourceTemplate) {
                $resourceTemplateProperty = $resourceTemplate->resourceTemplateProperty($propertyId);
                if ($resourceTemplateProperty) {
                    $typeTemplate = $resourceTemplateProperty->dataType();
                }
            }

            $baseType = null;
            $uriLabels = [];
            if (substr((string) $typeTemplate, 0, 12) === 'customvocab:') {
                $customVocabId = (int) substr($typeTemplate, 12);
                $baseType = $customVocabBaseTypes[$customVocabId] ?? 'literal';
                $uriLabels = $this->customVocabUriLabels($customVocabId);
            }

            foreach ($propositions as $key => $proposition) {
                // TODO Manage all the cases (custom vocab is literal, item, uri, value suggest is uri).
                // TODO Remove management of proposition without resource template (but the template may have been modified).
                if ($typeTemplate) {
                    $type = $typeTemplate;
                } elseif (array_key_exists('@uri', $proposition['original'])) {
                    $type = 'uri';
                } elseif (array_key_exists('@resource', $proposition['original'])) {
                    $type = 'resource';
                } elseif (array_key_exists('@value', $proposition['original'])) {
                    $type = 'literal';
                } else {
                    $type = 'unknown';
                }

                $isTermDatatype = $contributive->isTermDatatype($term, $type);

                $typeColon = strtok($type, ':');
                switch ($type) {
                    case 'literal':
                    case 'boolean':
                    case 'html':
                    case 'xml':
                    case $typeColon === 'numeric':
                    case $typeColon === 'customvocab' && $baseType === 'literal':
                        $original = $proposition['original']['@value'] ?? '';
                        $proposed = $proposition['proposed']['@value'] ?? '';

                        // Nothing to do if there is no proposition and no original.
                        $hasOriginal = (bool) strlen($original);
                        $hasProposition = (bool) strlen($proposed);
                        if (!$hasOriginal && !$hasProposition) {
                            unset($proposal[$term][$key]);
                            continue 2;
                        }

                        // TODO Keep the key order of the value in the list of values of each term to simplify validation.

                        $prop = &$proposal[$term][$key];
                        if ($original === $proposed) {
                            $prop['value'] = $this->resourceValue($term, $original);
                            $prop['value_updated'] = $prop['value'];
                            $prop['validated'] = true;
                            $prop['process'] = 'keep';
                        } elseif (!strlen($proposed)) {
                            // If no proposition, the user wants to remove a value, so check if it still exists.
                            // Either the value is validated, either it is not (to be removed, edited or appended).
                            $prop['value'] = $this->resourceValue($term, $original);
                            $prop['value_updated'] = null;
                            $prop['validated'] = !$prop['value'];
                            $prop['process'] = $isEditable && $isTermDatatype
                                ? 'remove'
                                // A value to remove is not a fillable value.
                                : 'keep';
                        } elseif (!strlen($original)
                            // Even if there is no original, check if a new
                            // value has been appended.
                            && !$this->resourceValue($term, $proposed)
                        ) {
                            // The original value may have been removed or appended:
                            // this is not really determinable.
                            $prop['value'] = null;
                            $prop['value_updated'] = $this->resourceValue($term, $proposed);
                            $prop['validated'] = (bool) $prop['value_updated'];
                            $prop['process'] = $isFillable && $isTermDatatype
                                ? 'append'
                                // A value to append is not an editable value.
                                : 'keep';
                        } elseif ($proposedValue = $this->resourceValue($term, $proposed)) {
                            $prop['value'] = $this->resourceValue($term, $original);
                            $prop['value_updated'] = $proposedValue;
                            $prop['validated'] = true;
                            $prop['process'] = 'keep';
                        } elseif ($originalValue = $this->resourceValue($term, $original)) {
                            $prop['value'] = $originalValue;
                            $prop['value_updated'] = $this->resourceValue($term, $proposed);
                            $prop['validated'] = (bool) $prop['value_updated'];
                            $prop['process'] = $isEditable && $isTermDatatype
                                ? 'update'
                                // A value to update is not a fillable value.
                                : 'keep';
                        } else {
                            $prop['value'] = null;
                            $prop['value_updated'] = $this->resourceValue($term, $proposed);
                            $prop['validated'] = (bool) $prop['value_updated'];
                            $prop['process'] = 'keep';
                        }
                        unset($prop);
                        break;

                    case $typeColon === 'resource':
                    case $typeColon === 'customvocab' && $baseType === 'resource':
                        $original = isset($proposition['original']['@resource']) ? (int) $proposition['original']['@resource'] : 0;
                        $proposed = isset($proposition['proposed']['@resource']) ? (int) $proposition['proposed']['@resource'] : 0;

                        // Nothing to do if there is no proposition and no original.
                        $hasOriginal = (bool) $original;
                        $hasProposition = (bool) $proposed;
                        if (!$hasOriginal && !$hasProposition) {
                            unset($proposal[$term][$key]);
                            continue 2;
                        }

                        // TODO Keep the key order of the value in the list of values of each term to simplify validation.

                        $prop = &$proposal[$term][$key];
                        if ($original === $proposed) {
                            $prop['value'] = $this->resourceValueResource($term, $original);
                            $prop['value_updated'] = $prop['value'];
                            $prop['validated'] = true;
                            $prop['process'] = 'keep';
                        } elseif (!$proposed) {
                            // If no proposition, the user wants to remove a value, so check if it still exists.
                            // Either the value is validated, either it is not (to be removed, edited or appended).
                            $prop['value'] = $this->resourceValueResource($term, $original);
                            $prop['value_updated'] = null;
                            $prop['validated'] = !$prop['value'];
                            $prop['process'] = $isEditable && $isTermDatatype
                                ? 'remove'
                                // A value to remove is not a fillable value.
                                : 'keep';
                        } elseif (!$original
                            // Even if there is no original, check if a new
                            // value has been appended.
                            && !$this->resourceValueResource($term, $proposed)
                        ) {
                            // The original value may have been removed or appended:
                            // this is not really determinable.
                            $prop['value'] = null;
                            $prop['value_updated'] = $this->resourceValueResource($term, $proposed);
                            $prop['validated'] = (bool) $prop['value_updated'];
                            $prop['process'] = $isFillable && $isTermDatatype
                                ? 'append'
                                // A value to append is not an editable value.
                                : 'keep';
                        } elseif ($proposedValue = $this->resourceValueResource($term, $proposed)) {
                            $prop['value'] = $this->resourceValueResource($term, $original);
                            $prop['value_updated'] = $proposedValue;
                            $prop['validated'] = true;
                            $prop['process'] = 'keep';
                        } elseif ($originalValue = $this->resourceValueResource($term, $original)) {
                            $prop['value'] = $originalValue;
                            $prop['value_updated'] = $this->resourceValueResource($term, $proposed);
                            $prop['validated'] = (bool) $prop['value_updated'];
                            $prop['process'] = $isEditable && $isTermDatatype
                                ? 'update'
                                // A value to update is not a fillable value.
                                : 'keep';
                        } else {
                            $prop['value'] = null;
                            $prop['value_updated'] = $this->resourceValueResource($term, $proposed);
                            $prop['validated'] = (bool) $prop['value_updated'];
                            $prop['process'] = 'keep';
                        }
                        unset($prop);
                        break;

                    case $typeColon === 'customvocab' && $baseType === 'uri':
                        $proposedValue['@label'] = $uriLabels[$proposedValue['@uri'] ?? ''] ?? '';
                        // No break.
                    case 'uri':
                    case $typeColon === 'valuesuggest':
                    case $typeColon === 'valuesuggestall':
                        $originalUri = $proposition['original']['@uri'] ?? '';
                        $originalLabel = $proposition['original']['@label'] ?? '';
                        $original = $originalUri . $originalLabel;

                        $proposedUri = $proposition['proposed']['@uri'] ?? '';
                        $proposedLabel = $proposition['proposed']['@label'] ?? '';
                        $proposed = $proposedUri . $proposedLabel;

                        // Nothing to do if there is no proposition and no original.
                        $hasOriginal = (bool) strlen($originalUri);
                        $hasProposition = (bool) strlen($proposedUri);
                        if (!$hasOriginal && !$hasProposition) {
                            unset($proposal[$term][$key]);
                            continue 2;
                        }

                        // TODO Keep the key order of the value in the list of values of each term to simplify validation.

                        $prop = &$proposal[$term][$key];
                        if ($original === $proposed) {
                            $prop['value'] = $this->resourceValueUri($term, $originalUri);
                            $prop['value_updated'] = $prop['value'];
                            $prop['validated'] = true;
                            $prop['process'] = 'keep';
                        } elseif (!strlen($proposed)) {
                            // If no proposition, the user wants to remove a value, so check if it still exists.
                            // Either the value is validated, either it is not (to be removed, edited or appended).
                            $prop['value'] = $this->resourceValueUri($term, $originalUri);
                            $prop['value_updated'] = null;
                            $prop['validated'] = !$prop['value'];
                            $prop['process'] = $isEditable && $isTermDatatype
                                ? 'remove'
                                // A value to remove is not a fillable value.
                                : 'keep';
                        } elseif (!strlen($original)
                            // Even if there is no original, check if a new
                            // value has been appended.
                            && !$this->resourceValueUri($term, $proposedUri)
                        ) {
                            // The original value may have been removed or appended:
                            // this is not really determinable.
                            $prop['value'] = null;
                            $prop['value_updated'] = $this->resourceValueUri($term, $proposedUri);
                            $prop['validated'] = (bool) $prop['value_updated'];
                            $prop['process'] = $isFillable && $isTermDatatype
                                ? 'append'
                                // A value to append is not an editable value.
                                : 'keep';
                        } elseif ($proposedValue = $this->resourceValueUri($term, $proposedUri)) {
                            $prop['value'] = $this->resourceValueUri($term, $originalUri);
                            $prop['value_updated'] = $proposedValue;
                            $prop['validated'] = true;
                            $prop['process'] = 'keep';
                        } elseif ($originalValue = $this->resourceValueUri($term, $originalUri)) {
                            $prop['value'] = $originalValue;
                            $prop['value_updated'] = $this->resourceValueUri($term, $proposedUri);
                            $prop['validated'] = (bool) $prop['value_updated'];
                            $prop['process'] = $isEditable && $isTermDatatype
                                ? 'update'
                                // A value to update is not a fillable value.
                                : 'keep';
                        } else {
                            $prop['value'] = null;
                            $prop['value_updated'] = $this->resourceValueUri($term, $proposedUri);
                            $prop['validated'] = (bool) $prop['value_updated'];
                            $prop['process'] = 'keep';
                        }
                        unset($prop);
                        break;

                    default:
                        $original = $proposition['original']['@value'] ?? '';

                        // Nothing to do if there is no original.
                        $hasOriginal = (bool) strlen($original);
                        if (!$hasOriginal) {
                            unset($proposal[$term][$key]);
                            continue 2;
                        }

                        $prop = &$proposal[$term][$key];
                        $prop['value'] = null;
                        $prop['value_updated'] = null;
                        $prop['validated'] = false;
                        $prop['process'] = 'keep';
                        unset($prop);
                        break;
                }
            }
        }

        // Normalize sub-proposal.
        if (!$isSubTemplate) {
            foreach ($medias ? array_keys($medias) : [] as $indexProposalMedia) {
                $indexProposalMedia = (int) $indexProposalMedia;
                // TODO Currently, only new media are managed as sub-resource: contribution for new resource, not contribution for existing item with media at the same time.
                $proposal['media'][$indexProposalMedia] = $this->proposalNormalizeForValidation($indexProposalMedia);
            }
        }

        return $proposal;
    }

    /**
     * Get contributive data (editable, fillable, etc.) via resource template.
     */
    public function contributiveData(): \Contribute\Mvc\Controller\Plugin\ContributiveData
    {
        static $contributive;
        if (!$contributive) {
            $contributive = $this->getServiceLocator()->get('ControllerPluginManager')
                ->get('contributiveData');
            $contributive = clone $contributive;
            $contributive($this->resourceTemplate());
        }
        return $contributive;
    }

    /**
     * A contribution is never public and is managed only by admins and owner.
     *
     * This method is added only to simplify views.
     */
    public function isPublic(): bool
    {
        return false;
    }

    /**
     * Get the thumbnail of this resource (the contributed one).
     *
     * @return \Omeka\Api\Representation\AssetRepresentation|null
     */
    public function thumbnail()
    {
        $contributionResource = $this->resource();
        return $contributionResource
            ? $contributionResource->thumbnail()
            : null;
    }

    /**
     * Get the title of the contributed resource.
     */
    public function title(): string
    {
        $contributionResource = $this->resource();
        return $contributionResource
            ? (string) $contributionResource->getTitle()
            : '';
    }

    /**
     * Get the display title for this contribution.
     *
     * The title is the resource one if any, else the proposed one.
     */
    public function displayTitle(?string $default = null): ?string
    {
        $contributionResource = $this->resource();
        if ($contributionResource) {
            return $contributionResource->displayTitle($default);
        }

        $template = $this->resourceTemplate();
        $titleTerm = $template && $template->titleProperty()
            ? $template->titleProperty()->term()
            : 'dcterms:title';

        $titles = $this->proposedValues($titleTerm);

        return ($titles ? reset($titles)['proposed']['@value'] ?? null : null)
            ?? $this->title()
            ?? $default
            ?? $this->getServiceLocator()->get('MvcTranslator')->translate('[Untitled]');
    }

    /**
     * Get all proposal of this contribution by term with template property.
     *
     * Values of the linked template (media) are not included.
     *
     * @see \Omeka\Api\Representation\AbstractResourceEntityRepresentation::values()
     * @uses \Contribute\View\Helper\ContributionFields
     */
    public function values(): array
    {
        if (isset($this->values)) {
            return $this->values;
        }

        /** @var \Contribute\View\Helper\ContributionFields $contributionFields */
        $contributionFields = $this->getViewHelper('contributionFields');
        // No event triggered for now.
        $contributionResource = $this->resource();
        $this->values = $contributionFields($contributionResource, $this);
        return $this->values;
    }

    /**
     * Get media proposals of this contribution by term with template property.
     *
     * @see \Omeka\Api\Representation\AbstractResourceEntityRepresentation::values()
     * @uses \Contribute\View\Helper\ContributionFields
     */
    public function valuesMedias(): array
    {
        if (isset($this->valuesMedias)) {
            return $this->valuesMedias;
        }

        $this->valuesMedia = [];

        // No event triggered for now.
        $contributionResource = $this->resource();
        if ($contributionResource && !$contributionResource instanceof \Omeka\Api\Representation\ItemRepresentation) {
            return [];
        }

        /** @var \Contribute\View\Helper\ContributionFields $contributionFields */
        $contributionFields = $this->getViewHelper('contributionFields');
        $contributive = $this->contributiveData();
        $contributiveMedia = $contributive->contributiveMedia();
        if (!$contributiveMedia) {
            return [];
        }

        $resourceTemplateMedia = $contributiveMedia->template();
        foreach (array_keys($this->proposalMedias()) as $indexProposalMedia) {
            // TODO Currently, only new media are managed as sub-resource: contribution for new resource, not contribution for existing item with media at the same time.
            // So, there is no resource, but a proposal for a new media.
            $indexProposalMedia = (int) $indexProposalMedia;
            $this->valuesMedia[$indexProposalMedia] = $contributionFields(null, $this, $resourceTemplateMedia, true, $indexProposalMedia);
        }
        return $this->valuesMedia;
    }

    /**
     * Get the display markup for all values of this resource, medias included.
     *      *
     * Options:
     *
     * + viewName: Name of view script, or a view model. Default
     *   "site/contribution-values"
     */
    public function displayValues(array $options = []): string
    {
        $options['site'] = $this->getServiceLocator()->get('ControllerPluginManager')->get('currentSite');
        $options['contribution'] = $this;

        if (!isset($options['viewName'])) {
            $options['viewName'] = 'common/contribution-values';
        }

        // No event triggered for now.
        $options['values'] = $this->values();
        $options['valuesMedias'] = $this->valuesMedias();

        $template = $this->resourceTemplate();
        $options['templateProperties'] = $template ? $template->resourceTemplateProperties() : [];

        $partial = $this->getViewHelper('partial');
        return $partial($options['viewName'], $options);
    }

    /**
     * Get an HTML link to a resource (the contributed one).
     *
     * @param string $text The text to be linked
     * @param string $action
     * @param array $attributes HTML attributes, key and value
     */
    public function linkResource(string $text, ?string $action = null, array $attributes = []): string
    {
        $contributionResource = $this->resource();
        if (!$contributionResource) {
            return $text;
        }
        $link = $contributionResource->link($text, $action, $attributes);
        // When the resource is a new one, go directly to the resource, since
        // the contribution is the source of the resource.
        if (!$this->isPatch()) {
            return $link;
        }
        // TODO Improve the way to append the fragment.
        return preg_replace('~ href="(.+?)"~', ' href="$1#contribution"', $link, 1);
    }

    /**
     * Get a "pretty" link to this resource containing a thumbnail and
     * display title.
     *
     * @param string $thumbnailType Type of thumbnail to show
     * @param string|null $titleDefault See $default param for displayTitle()
     * @param string|null $action Action to link to (see link() and linkRaw())
     * @param array $attributes HTML attributes, key and value
     */
    public function linkPretty(
        $thumbnailType = 'square',
        $titleDefault = null,
        $action = null,
        array $attributes = null
    ): string {
        $escape = $this->getViewHelper('escapeHtml');
        $thumbnail = $this->getViewHelper('thumbnail');
        $linkContent = sprintf(
            '%s<span class="resource-name">%s</span>',
            $thumbnail($this, $thumbnailType),
            $escape($this->displayTitle($titleDefault))
        );
        if (empty($attributes['class'])) {
            $attributes['class'] = 'resource-link';
        } else {
            $attributes['class'] .= ' resource-link';
        }
        return $this->linkRaw($linkContent, $action, $attributes);
    }

    /**
     * Get a "pretty" link to this resource containing a thumbnail and
     * display title.
     *
     * @param string $thumbnailType Type of thumbnail to show
     * @param string|null $titleDefault See $default param for displayTitle()
     * @param string|null $action Action to link to (see link() and linkRaw())
     * @param array $attributes HTML attributes, key and value
     */
    public function linkPrettyResource(
        $thumbnailType = 'square',
        $titleDefault = null,
        $action = null,
        array $attributes = null
    ): string {
        $contributionResource = $this->resource();
        if (!$contributionResource) {
            return $this->displayTitle($titleDefault);
        }
        $link = $contributionResource->linkPretty($thumbnailType, $titleDefault, $action, $attributes);
        // TODO Improve the way to append the fragment.
        return preg_replace('~ href="(.+?)"~', ' href="$1#contribution"', $link, 1);
    }

    /**
     * Return the URL to this resource.
     *
     * Unlike parent method, the action is used for the site.
     *
     * {@inheritDoc}
     * @see \Omeka\Api\Representation\AbstractResourceRepresentation::url()
     */
    public function url($action = null, $canonical = false)
    {
        $status = $this->getServiceLocator()->get('Omeka\Status');
        if ($status->isAdminRequest()) {
            return $this->adminUrl($action, $canonical);
        } elseif ($status->isSiteRequest()) {
            return $this->siteUrl($status->getRouteMatch()->getParam('site-slug'), $canonical, $action);
        } else {
            return null;
        }
    }

    /**
     * Get the site url of the contribution.
     *
     * An argument is added to set the action (add, edit, submit, etc.).
     *
     * {@inheritDoc}
     * @see \Omeka\Api\Representation\AbstractResourceRepresentation::siteUrl()
     */
    public function siteUrl($siteSlug = null, $canonical = false, $action = null)
    {
        if (!$siteSlug) {
            $siteSlug = $this->getServiceLocator()->get('Application')
                ->getMvcEvent()->getRouteMatch()->getParam('site-slug');
        }
        $url = $this->getViewHelper('Url');
        return $url(
            'site/contribution-id',
            [
                'site-slug' => $siteSlug,
                'resource' => 'contribution',
                // TODO The default action "view" will be "show" later.
                'action' => $action ?? 'view',
                'id' => $this->id(),
            ],
            ['force_canonical' => $canonical]
        );
    }

    public function siteUrlResource($siteSlug = null, $canonical = false, $action = null)
    {
        $contributionResource = $this->resource();
        return $contributionResource
            ? $contributionResource->siteUrl($siteSlug, $canonical, $action)
            : null;
    }

    /**
     * Get the list of uris and labels of a specific custom vocab.
     *
     * @see \CustomVocab\DataType\CustomVocab::getUriForm()
     */
    protected function customVocabUriLabels(int $customVocabId): array
    {
        static $uriLabels = [];
        if (!isset($uriLabels[$customVocabId])) {
            $api = $this->getServiceLocator()->get('ControllerPluginManager')->get('api');
            $uris = $api->searchOne('custom_vocabs', ['id' => $customVocabId], ['returnScalar' => 'uris'])->getContent();
            $uris = array_map('trim', preg_split("/\r\n|\n|\r/", (string) $uris));
            $matches = [];
            $values = [];
            foreach ($uris as $uri) {
                if (preg_match('/^(\S+) (.+)$/', $uri, $matches)) {
                    $values[$matches[1]] = $matches[2];
                } elseif (preg_match('/^(.+)/', $uri, $matches)) {
                    $values[$matches[1]] = '';
                }
            }
            $uriLabels[$customVocabId] = $values;
        }
        return $uriLabels[$customVocabId];
    }
}

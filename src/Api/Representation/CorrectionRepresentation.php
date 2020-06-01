<?php
namespace Correction\Api\Representation;

use Omeka\Api\Representation\AbstractEntityRepresentation;

class CorrectionRepresentation extends AbstractEntityRepresentation
{
    public function getControllerName()
    {
        return 'correction';
    }

    public function getJsonLdType()
    {
        return 'o-module-correction:Correction';
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

        return [
            'o:id' => $this->id(),
            'o:resource' => $this->resource()->getReference(),
            'o-module-correction:token' => $token,
            'o:email' => $this->email(),
            'o-module-correction:reviewed' => $this->reviewed(),
            'o-module-correction:proposal' => $this->proposal(),
            'o:created' => $created,
            'o:modified' => $modified,
        ];
    }

    /**
     * @return \Omeka\Api\Representation\AbstractResourceEntityRepresentation
     */
    public function resource()
    {
        return $this->getAdapter('resources')
            ->getRepresentation($this->resource->getResource());
    }

    /**
     * @return \Correction\Api\Representation\TokenRepresentation
     */
    public function token()
    {
        return $this->getAdapter('correction_tokens')
            ->getRepresentation($this->resource->getToken());
    }

    /**
     * @return string
     */
    public function email()
    {
        return $this->resource->getEmail();
    }

    /**
     * @return bool
     */
    public function reviewed()
    {
        return $this->resource->getReviewed();
    }

    /**
     * @return array
     */
    public function proposal()
    {
        return $this->resource->getProposal();
    }

    /**
     * @return \DateTime
     */
    public function created()
    {
        return $this->resource->getCreated();
    }

    /**
     * @return \DateTime|null
     */
    public function modified()
    {
        return $this->resource->getModified();
    }

    /**
     * Get all proposed corrections for a term.
     *
     * @param string $term
     * @return array
     */
    public function proposedValues($term)
    {
        $data = $this->proposal();
        return empty($data[$term])
            ? []
            : $data[$term];
    }

    /**
     * Get a specific proposed correction for a term.
     *
     * @param string $term
     * @param string $original
     * @return array|null Empty string value is used when the value is removed.
     */
    public function proposedValue($term, $original)
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
     * Get a specific proposed correction uri for a term.
     *
     * @param string $term
     * @param string $original
     * @return array|null Empty string uri is used when the value is removed.
     */
    public function proposedUriValue($term, $originalUri, $originalLabel)
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
     * @param string $term
     * @param string $original
     * @return bool|null Null means no value, false if corrected, true if
     * approved.
     */
    public function isApprovedValue($term, $original)
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
     *
     * @param string $term
     * @param string $string
     * @return \Omeka\Api\Representation\ValueRepresentation|null
     */
    public function resourceValue($term, $string)
    {
        if ($string === '') {
            return null;
        }
        //  TODO Manage correction of non literal values. Remove uri?
        $values = $this->resource()->value($term, ['all' => true, 'default' => []]);
        foreach ($values as $value) {
            if ($value->value() === $string) {
                return $value;
            }
        }
        return null;
    }

    /**
     * Check if a uri exists in original resource.
     *
     * @param string $term
     * @param string $string
     * @return \Omeka\Api\Representation\ValueRepresentation|null
     */
    public function resourceValueUri($term, $string)
    {
        if ($string === '') {
            return null;
        }
        //  TODO Manage correction of non literal values.
        $values = $this->resource()->value($term, ['type' => 'uri', 'all' => true, 'default' => []]);
        foreach ($values as $value) {
            if ($value->uri() === $string) {
                return $value;
            }
        }
        return null;
    }

    /**
     * Check proposed correction against the current resource.
     *
     * @return array
     */
    public function proposalCheck()
    {
        $services = $this->getServiceLocator();
        $propertyIds = $services->get('ControllerPluginManager')->get('propertyIdsByTerms');
        $propertyIds = $propertyIds();

        $editable = $this->editableData();

        $proposal = $this->proposal();
        foreach ($proposal as $term => $propositions) {
            $isCorrigible = $editable->isTermCorrigible($term);
            $isFillable = $editable->isTermFillable($term);
            if (!$isCorrigible && !$isFillable) {
                // Skipped in the case options changed between corrections and moderation.
                // continue;
            }

            // In the case that the property was removed.
            if (!isset($propertyIds[$term])) {
                unset($proposal[$term]);
                continue;
            }

            foreach ($propositions as $key => $proposition) {
                // TODO Manage all the cases (custom vocab is literal, value suggest is uri).
                $type = null;
                if (array_key_exists('@uri', $proposition['original'])) {
                    $type = 'uri';
                } elseif (array_key_exists('@value', $proposition['original'])) {
                    $type = 'literal';
                }

                if (!$editable->isDatatypeAllowed($type)) {
                    $type = null;
                }

                switch ($type) {
                    case 'literal':
                        $original = $proposition['original']['@value'];
                        $proposed = $proposition['proposed']['@value'];

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
                            // Either the value is validated, either it is not (to be removed, corrected or appended).
                            $prop['value'] = $this->resourceValue($term, $original);
                            $prop['value_updated'] = null;
                            $prop['validated'] = !$prop['value'];
                            $prop['process'] = $isCorrigible
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
                            $prop['process'] = $isFillable
                                ? 'append'
                                // A value to append is not a corrigible value.
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
                            $prop['process'] = $isCorrigible
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

                    case 'uri':
                        $originalUri = $proposition['original']['@uri'];
                        $originalLabel = $proposition['original']['@label'];
                        $original = $originalUri . $originalLabel;

                        $proposedUri = $proposition['proposed']['@uri'];
                        $proposedLabel = $proposition['proposed']['@label'];
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
                            // Either the value is validated, either it is not (to be removed, corrected or appended).
                            $prop['value'] = $this->resourceValueUri($term, $originalUri);
                            $prop['value_updated'] = null;
                            $prop['validated'] = !$prop['value'];
                            $prop['process'] = $isCorrigible
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
                            $prop['process'] = $isFillable
                                ? 'append'
                                // A value to append is not a corrigible value.
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
                            $prop['process'] = $isCorrigible
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
                        $original = isset($proposition['original']['@value']) ? $proposition['original']['@value'] : '';

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

        return $proposal;
    }

    /**
     * Get the editable data (corrigible, fillable, etc.) of a resource.
     *
     * @param \Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource
     * @return \Correction\Mvc\Controller\Plugin\EditableData
     */
    public function editableData()
    {
        $editableData = $this->getServiceLocator()->get('ControllerPluginManager')
            ->get('editableData');
        return $editableData($this->resource());
    }

    public function siteUrl($siteSlug = null, $canonical = false)
    {
        if (!$siteSlug) {
            $siteSlug = $this->getServiceLocator()->get('Application')
                ->getMvcEvent()->getRouteMatch()->getParam('site-slug');
        }
        $url = $this->getViewHelper('Url');
        return $url(
            'site/correction',
            [
                'site-slug' => $siteSlug,
                'resource' => $this->resource()->getControllerName(),
                'id' => $this->id(),
            ],
            ['force_canonical' => $canonical]
        );
    }
}

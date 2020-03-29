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
     * @return \Omeka\Api\Representation\ValueRepresentation
     */
    public function resourceValue($term, $string)
    {
        if ($string === '') {
            return;
        }
        //  TODO Manage correction of non literal values.
        $values = $this->resource()->value($term, ['all' => true, 'default' => []]);
        foreach ($values as $value) {
            if ($value->value() === $string) {
                return $value;
            }
        }
    }

    /**
     * Check if a uri exists in original resource.
     *
     * @param string $term
     * @param string $string
     * @return \Omeka\Api\Representation\ValueRepresentation
     */
    public function resourceUriValue($term, $string)
    {
        if ($string === '') {
            return;
        }
        //  TODO Manage correction of non literal values.
        $values = $this->resource()->value($term, ['type' => 'uri', 'all' => true, 'default' => []]);
        foreach ($values as $value) {
            if ($value->uri() === $string) {
                return $value;
            }
        }
    }

    /**
     * Check proposed correction against the current resource.
     *
     * @return array
     */
    public function proposalCheck()
    {
        $services = $this->getServiceLocator();
        $api = $services->get('ControllerPluginManager')->get('api');

        $editable = $this->listEditableProperties();

        $proposal = $this->proposal();
        foreach ($proposal as $term => $propositions) {
            $isCorrigible = ($editable['corrigible_mode'] === 'whitelist' && isset($editable['corrigible'][$term]))
                || ($editable['corrigible_mode'] === 'blacklist' && !isset($editable['corrigible'][$term]))
                || ($editable['corrigible_mode'] === 'all');
            $isFillable = ($editable['fillable_mode'] === 'whitelist' && isset($editable['fillable'][$term]))
                || ($editable['fillable_mode'] === 'blacklist' && !isset($editable['fillable'][$term]))
                || ($editable['fillable_mode'] === 'all');
            if (!$isCorrigible && !$isFillable) {
                // Skipped in the case options changed between corrections and moderation.
                // continue;
            }

            // In the case that the property was removed.
            $property = $api->searchOne('properties', ['term' => $term])->getContent();
            if (empty($property)) {
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

                switch ($type) {
                    default:
                        $original = isset($proposition['original']['@value']) ? $proposition['original']['@value'] : '';

                        // Nothing to do if there is no original.
                        $hasOriginal = (bool) strlen($original);
                        if (!$hasOriginal) {
                            unset($proposal[$term][$key]);
                            continue;
                        }

                        $prop = &$proposal[$term][$key];
                        $prop['value'] = null;
                        $prop['value_updated'] = null;
                        $prop['validated'] = false;
                        $prop['process'] = 'keep';
                        unset($prop);
                        break;

                    case 'literal':
                        $original = $proposition['original']['@value'];
                        $proposed = $proposition['proposed']['@value'];

                        // Nothing to do if there is no proposition and no original.
                        $hasOriginal = (bool) strlen($original);
                        $hasProposition = (bool) strlen($proposed);
                        if (!$hasOriginal && !$hasProposition) {
                            unset($proposal[$term][$key]);
                            continue;
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
                        } elseif (!strlen($original)) {
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
                            continue;
                        }

                        // TODO Keep the key order of the value in the list of values of each term to simplify validation.

                        $prop = &$proposal[$term][$key];
                        if ($original === $proposed) {
                            $prop['value'] = $this->resourceValue($term, $originalUri);
                            $prop['value_updated'] = $prop['value'];
                            $prop['validated'] = true;
                            $prop['process'] = 'keep';
                        } elseif (!strlen($proposed)) {
                            // If no proposition, the user wants to remove a value, so check if it still exists.
                            // Either the value is validated, either it is not (to be removed, corrected or appended).
                            $prop['value'] = $this->resourceValue($term, $originalUri);
                            $prop['value_updated'] = null;
                            $prop['validated'] = !$prop['value'];
                            $prop['process'] = $isCorrigible
                                ? 'remove'
                                // A value to remove is not a fillable value.
                                : 'keep';
                        } elseif (!strlen($original)) {
                            // The original value may have been removed or appended:
                            // this is not really determinable.
                            $prop['value'] = null;
                            $prop['value_updated'] = $this->resourceValue($term, $proposedUri);
                            $prop['validated'] = (bool) $prop['value_updated'];
                            $prop['process'] = $isFillable
                                ? 'append'
                                // A value to append is not a corrigible value.
                                : 'keep';
                        } elseif ($proposedValue = $this->resourceUriValue($term, $proposedUri)) {
                            $prop['value'] = $this->resourceUriValue($term, $originalUri);
                            $prop['value_updated'] = $proposedValue;
                            $prop['validated'] = true;
                            $prop['process'] = 'keep';
                        } elseif ($originalValue = $this->resourceUriValue($term, $originalUri)) {
                            $prop['value'] = $originalValue;
                            $prop['value_updated'] = $this->resourceValue($term, $proposedUri);
                            $prop['validated'] = (bool) $prop['value_updated'];
                            $prop['process'] = $isCorrigible
                                ? 'update'
                                // A value to update is not a fillable value.
                                : 'keep';
                        } else {
                            $prop['value'] = null;
                            $prop['value_updated'] = $this->resourceValue($term, $proposedUri);
                            $prop['validated'] = (bool) $prop['value_updated'];
                            $prop['process'] = 'keep';
                        }
                        unset($prop);
                        break;
                }
            }
        }

        return $proposal;
    }

    /**
     * Get the list of editable (corrigible and fillable) property ids by terms.
     *
     *  The list come from the resource template if it is configured, else the
     *  default list is used.
     *
     * @param \Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource
     * @return array
     */
    public function listEditableProperties()
    {
        $listEditableProperties = $this->getServiceLocator()->get('ControllerPluginManager')
            ->get('listEditableProperties');
        return $listEditableProperties($this->resource());
    }
}

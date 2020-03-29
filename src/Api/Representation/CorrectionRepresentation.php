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
     * @return \DateTime
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
        $values = $this->resource()->value($term, ['all' => true , 'default' => []]);
        foreach ($values as $value) {
            if ($value->value() === $string) {
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
        static $check;

        if (isset($check)) {
            return $check;
        }

        $services = $this->getServiceLocator();
        $api = $services->get('ControllerPluginManager')->get('api');

        $editable = $this->resourceTemplateSettings();
        $corrigible = $editable['corrigible'];
        $fillable = $editable['fillable'];

        if (empty($corrigible) && empty($fillable)) {
            $check = [];
            return $check;
        }

        $proposal = $this->proposal();
        foreach ($proposal as $term => $propositions) {
            $isCorrigible = in_array($term, $corrigible);
            $isFillable = in_array($term, $fillable);
            // In the case that the options changed between corrections an moderation.
            if (!$isCorrigible && !$isFillable) {
                // unset($proposal[$term]);
                // continue;
            }

            // In the case that the property was removed.
            $property = $api->searchOne('properties', ['term' => $term])->getContent();
            if (empty($property)) {
                // unset($proposal[$term]);
                // continue;
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
                        }
                        // If no proposition, the user wants to remove a value, so check if it still exists.
                        // Either the value is validated, either it is not (to be removed, corrected or appended).
                        elseif (!strlen($proposed)) {
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

                        $proposed_uri = $proposition['proposed']['@uri'];
                        $proposedLabel = $proposition['proposed']['@label'];

                        // Nothing to do if there is no proposition and no original.
                        $hasOriginal = (bool) strlen($originalUri);
                        $hasProposition = (bool) strlen($proposed_uri);
                        if (!$hasOriginal && !$hasProposition) {
                            unset($proposal[$term][$key]);
                            continue;
                        }

                        // TODO Keep the key order of the value in the list of values of each term to simplify validation.

                        $prop = &$proposal[$term][$key];
                        if ($originalUri === $proposed_uri && $originalLabel === $proposedLabel) {
                            $prop['value'] = $this->resourceValue($term, $originalLabel);
                            $prop['value_updated'] = $prop['value'];
                            $prop['validated'] = true;
                            $prop['process'] = 'keep';
                        }
                        // If no proposition, the user wants to remove a value, so check if it still exists.
                        // Either the value is validated, either it is not (to be removed, corrected or appended).
                        elseif (!strlen($proposedLabel)) {
                            $prop['value'] = $this->resourceValue($term, $originalLabel);
                            $prop['value_updated'] = null;
                            $prop['validated'] = !$prop['value'];
                            $prop['process'] = $isCorrigible
                                ? 'remove'
                                // A value to remove is not a fillable value.
                                : 'keep';
                        } elseif (!strlen($originalLabel)) {
                            // The original value may have been removed or appended:
                            // this is not really determinable.
                            $prop['value'] = null;
                            $prop['value_updated'] = $this->resourceValue($term, $proposedLabel);
                            $prop['validated'] = (bool) $prop['value_updated'];
                            $prop['process'] = $isFillable
                                ? 'append'
                                // A value to append is not a corrigible value.
                                : 'keep';
                        } elseif ($originalValue = $this->resourceValue($term, $original)) {
                            $prop['value'] = $this->resourceValue($term, $originalLabel);
                            $prop['value_updated'] = $this->resourceValue($term, $proposedLabel);
                            $prop['validated'] = (bool) $prop['value_updated'];
                            $prop['process'] = $isCorrigible
                                ? 'update'
                                // A value to update is not a fillable value.
                                : 'keep';
                        } else {
                            $prop['value'] = null;
                            $prop['value_updated'] = $this->resourceValue($term, $proposedLabel);
                            $prop['validated'] = (bool) $prop['value_updated'];
                            $prop['process'] = 'keep';
                        }
                        unset($prop);
                        break;
                }
            }
        }

        $check = $proposal;
        return $check;
    }

    public function resourceTemplateSettings()
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $plugins = $services->get('ControllerPluginManager');
        $api = $plugins->get('api');
        $resource = $this->resource();

        $result = [
            'corrigible' => [],
            'fillable' => [],
        ];

        $resourceTemplate = $resource->resourceTemplate();
        if ($resourceTemplate) {
            $resourceTemplateCorrectionPartMap = $plugins->get('resourceTemplateCorrectionPartMap');
            $correctionPartMap = $resourceTemplateCorrectionPartMap($resourceTemplate->id());
            foreach ($correctionPartMap['corrigible'] as $term) {
                $property = $api->searchOne('properties', ['term' => $term])->getContent();
                if ($property) {
                    $result['corrigible'][$property->id()] = $term;
                }
            }
            foreach ($correctionPartMap['fillable'] as $term) {
                $property = $api->searchOne('properties', ['term' => $term])->getContent();
                if ($property) {
                    $result['fillable'][$property->id()] = $term;
                }
            }
        }

        if (!count($result['corrigible']) && !count($result['fillable'])) {
            $result['corrigible'] = $settings->get('correction_properties_corrigible', []);
            $result['fillable'] = $settings->get('correction_properties_fillable', []);
        }

        return $result;
    }
}

<?php declare(strict_types=1);
namespace Contribute\Api\Representation;

use Omeka\Api\Representation\AbstractEntityRepresentation;

class ContributionRepresentation extends AbstractEntityRepresentation
{
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

        $res = $this->resource();
        $owner = $this->owner();

        return [
            'o:id' => $this->id(),
            'o:resource' => $res ? $res->getReference() : null,
            'o:owner' => $owner ? $owner->getReference() : null,
            'o:email' => $owner ? null : $this->email(),
            'o-module-contribute:reviewed' => $this->reviewed(),
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
        $res = $this->resource->getResource();
        return $res
            ? $this->getAdapter('resources')->getRepresentation($res)
            : null;
    }

    /**
     * Get the owner representation of this resource.
     *
     * @return \Omeka\Api\Representation\UserRepresentation
     */
    public function owner()
    {
        return $this->getAdapter('users')
            ->getRepresentation($this->resource->getOwner());
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
     * @return \Contribute\Api\Representation\TokenRepresentation
     */
    public function token()
    {
        return $this->getAdapter('contribution_tokens')
            ->getRepresentation($this->resource->getToken());
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
     * Get all proposed contributions for a term.
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
     * Get a specific proposed contribution for a term.
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
     * Get a specific proposed contribution uri for a term.
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
     * @return bool|null Null means no value, false if edited, true if
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
        //  TODO Manage contribution of non literal values. Remove uri?
        $values = $this->resource()->value($term, ['all' => true]);
        foreach ($values as $value) {
            if ($value->value() === $string) {
                return $value;
            }
        }
        return null;
    }

    /**
     * Check if a resource value exists in original resource.
     *
     * @param string $term
     * @param string $string
     * @return \Omeka\Api\Representation\ValueRepresentation|null
     */
    public function resourceValueResource($term, $string)
    {
        $string = (int) $string;
        if (!$string) {
            return null;
        }
        $values = $this->resource()->value($term, ['all' => true]);
        foreach ($values as $value) {
            $type = $value->type();
            if (strtok($type, ':') === 'resource') {
                $valueResource = $value->valueResource();
                if ($valueResource->id() === $string) {
                    return $value;
                }
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
        // To get only uris and value suggest values require to get all values.
        $values = $this->resource()->value($term, ['all' => true]);
        foreach ($values as $value) {
            $type = $value->type();
            if (($type === 'uri' || in_array(strtok($type, ':'), ['valuesuggest', 'valuesuggestall']))
                && $value->uri() === $string
            ) {
                return $value;
            }
        }
        return null;
    }

    /**
     * Check proposed contribution against the current resource.
     *
     * @return array
     */
    public function proposalCheck()
    {
        $services = $this->getServiceLocator();
        $propertyIds = $services->get('ControllerPluginManager')->get('propertyIdsByTerms');
        $propertyIds = $propertyIds();

        $contributive = $this->contributiveData();

        $resourceTemplate = $this->resource()->resourceTemplate();

        $proposal = $this->proposal();
        foreach ($proposal as $term => $propositions) {
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

            foreach ($propositions as $key => $proposition) {
                // TODO Manage all the cases (custom vocab is literal, value suggest is uri).
                $type = 'unknown';
                if ($typeTemplate) {
                    $type = $typeTemplate;
                } else {
                    if (array_key_exists('@uri', $proposition['original'])) {
                        $type = 'uri';
                    } elseif (array_key_exists('@resource', $proposition['original'])) {
                        $type = 'resource';
                    } elseif (array_key_exists('@value', $proposition['original'])) {
                        $type = 'literal';
                    }
                }

                $isTermDatatype = $contributive->isTermDatatype($term, $type);

                switch ($type) {
                    case 'literal':
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

                    case strtok($type, ':') === 'resource':
                        $original = $proposition['original']['@resource'];
                        $proposed = $proposition['proposed']['@resource'];

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
                            $prop['value'] = $this->resourceValueResource($term, $original);
                            $prop['value_updated'] = $prop['value'];
                            $prop['validated'] = true;
                            $prop['process'] = 'keep';
                        } elseif (!strlen($proposed)) {
                            // If no proposition, the user wants to remove a value, so check if it still exists.
                            // Either the value is validated, either it is not (to be removed, edited or appended).
                            $prop['value'] = $this->resourceValueResource($term, $original);
                            $prop['value_updated'] = null;
                            $prop['validated'] = !$prop['value'];
                            $prop['process'] = $isEditable && $isTermDatatype
                                ? 'remove'
                                // A value to remove is not a fillable value.
                                : 'keep';
                        } elseif (!strlen($original)
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

                    case 'uri':
                    case in_array(strtok($type, ':'), ['valuesuggest', 'valuesuggestall']):
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

        return $proposal;
    }

    /**
     * Get the editable data (editable, fillable, etc.) of a resource.
     *
     * @param \Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource
     * @return \Contribute\Mvc\Controller\Plugin\ContributiveData
     */
    public function contributiveData()
    {
        $contributiveData = $this->getServiceLocator()->get('ControllerPluginManager')
            ->get('contributiveData');
        $res = $this->resource();
        $template = $res ? $res->resourceTemplate() : null;
        return $contributiveData($template);
    }

    /**
     * A contribution is never public and is managed only by admins and owner.
     *
     * This method is added only to simplify views.
     *
     * @return bool
     */
    public function isPublic()
    {
        return false;
    }

    /**
     * Get the resource name of the corresponding entity API adapter.
     *
     * @return string
     */
    public function resourceName()
    {
        return 'contributions';
    }

    /**
     * Get the thumbnail of this resource (the contributed one)..
     *
     * @return \Omeka\Api\Representation\AssetRepresentation|null
     */
    public function thumbnail()
    {
        $res = $this->resource();
        return $res
            ? $res->thumbnail()
            : null;
    }

    /**
     * Get the title of this resource (the contributed one).
     *
     * @return string
     */
    public function title()
    {
        $res = $this->resource();
        return $res
            ? $res->getTitle()
            : '';
    }

    /**
     * Get the display title for this resource (the contributed one).
     *
     * @param string|null $default
     * @return string|null
     */
    public function displayTitle($default = null)
    {
        $res = $this->resource();
        if ($res) {
            return $res->displayTitle($default);
        }

        if ($default === null) {
            $translator = $this->getServiceLocator()->get('MvcTranslator');
            $default = $translator->translate('[Untitled]');
        }

        return $default;
    }

    /**
     * Get an HTML link to a resource (the contributed one).
     *
     * @param string $text The text to be linked
     * @param string $action
     * @param array $attributes HTML attributes, key and value
     * @return string
     */
    public function linkResource($text, $action = null, $attributes = [])
    {
        $res = $this->resource();
        if (!$res) {
            return $text;
        }
        $link = $res->link($text, $action, $attributes);
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
     * @return string
     */
    public function linkPretty(
        $thumbnailType = 'square',
        $titleDefault = null,
        $action = null,
        array $attributes = null
    ) {
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
     * @return string
     */
    public function linkPrettyResource(
        $thumbnailType = 'square',
        $titleDefault = null,
        $action = null,
        array $attributes = null
    ) {
        $res = $this->resource();
        if (!$res) {
            return $this->displayTitle($titleDefault);
        }
        $link = $res->linkPretty($thumbnailType, $titleDefault, $action, $attributes);
        // TODO Improve the way to append the fragment.
        return preg_replace('~ href="(.+?)"~', ' href="$1#contribution"', $link, 1);
    }

    /**
     * Return the admin URL to this resource.
     *
     * @param string $action The route action
     * @param bool $canonical Whether to return an absolute URL
     * @return string
     */
    public function adminUrl($action = null, $canonical = false)
    {
        $url = $this->getViewHelper('Url');
        return $url(
            'admin/id',
            [
                'controller' => $this->getControllerName(),
                'action' => $action,
                'id' => $this->id(),
            ],
            ['force_canonical' => $canonical]
        );
    }

    /**
     * There is no page for the contribution, so it is the link to the resource
     * add/edition page.
     *
     * {@inheritDoc}
     * @see \Omeka\Api\Representation\AbstractResourceRepresentation::siteUrl()
     */
    public function siteUrl($siteSlug = null, $canonical = false)
    {
        if (!$siteSlug) {
            $siteSlug = $this->getServiceLocator()->get('Application')
                ->getMvcEvent()->getRouteMatch()->getParam('site-slug');
        }
        $url = $this->getViewHelper('Url');
        $resource = $this->resource();
        if (!$resource) {
            return $url(
                'site/contribute',
                [
                    'site-slug' => $siteSlug,
                    // TODO Support any new resources, not only item.
                    'resource' => 'item',
                ],
                ['force_canonical' => $canonical]
            );
        }

        return $url(
            'site/contribute-id',
            [
                'site-slug' => $siteSlug,
                'resource' => $resource->getControllerName(),
                'id' => $resource->id(),
            ],
            ['force_canonical' => $canonical]
        );
    }
}

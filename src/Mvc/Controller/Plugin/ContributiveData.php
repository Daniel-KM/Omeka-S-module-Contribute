<?php declare(strict_types=1);

namespace Contribute\Mvc\Controller\Plugin;

use ArrayObject;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;

class ContributiveData extends AbstractPlugin
{
    /**
     * @var \ArrayObject
     */
    protected $data;

    /**
     * Get contributive data (editable, fillable, etc.) of a resource template.
     *
     *  The list comes from the resource template if it is configured, else the
     *  default list is used.
     *
     * @param \AdvancedResourceTemplate\Api\Representation\ResourceTemplateRepresentation|\Omeka\Api\Representation\ResourceTemplateRepresentation|string|int|null $template
     * @return self
     */
    public function __invoke($resourceTemplate = null)
    {
        $this->data = new ArrayObject([
            'is_contributive' => false,
            'template' => null,
            'default_properties' => false,
            'editable_mode' => 'whitelist',
            'editable' => [],
            'fillable_mode' => 'whitelist',
            'fillable' => [],
            'datatype' => [],
            'datatypes_default' => [],
        ]);

        $controller = $this->getController();
        $propertyIdsByTerms = $controller->propertyIdsByTerms();
        $settings = $controller->settings();
        $this->data['datatypes_default'] = $settings->get('contribute_properties_datatype', []);

        // TODO Manage valuesuggest differently, because it is not a datatype.
        if (($has = array_search('valuesuggest', $this->data['datatypes_default'])) !== false) {
            unset($this->data['datatypes_default'][$has]);
        }

        $resourceTemplate = $this->resourceTemplate($resourceTemplate);

        if (!$resourceTemplate) {
            $resourceTemplateId = (int) $settings->get('contribute_template_default');
            if ($resourceTemplateId) {
                $resourceTemplate = $controller->api()->searchOne('resource_templates', ['id' => $resourceTemplateId])->getContent();
            }
        }

        if ($resourceTemplate) {
            $this->data['template'] = $resourceTemplate;
            /** @var \AdvancedResourceTemplate\Api\Representation\ResourceTemplatePropertyRepresentation $resourceTemplateProperty */
            foreach ($resourceTemplate->resourceTemplateProperties() as $resourceTemplateProperty) {
                $property = $resourceTemplateProperty->property();
                $propertyId = $property->id();
                $term = $property->term();
                $datatype = $resourceTemplateProperty->dataType();
                $this->data['datatype'][$term] = $datatype ? [$datatype] : $this->data['datatypes_default'];
                $rtpData = $resourceTemplateProperty->data();
                // TODO Manage repeatable property.
                $rtpData = reset($rtpData);
                if (!$rtpData) {
                    continue;
                }
                if ($rtpData->dataValue('editable', false)) {
                    $this->data['editable'][$term] = $propertyId;
                }
                if ($rtpData->dataValue('fillable', false)) {
                    $this->data['fillable'][$term] = $propertyId;
                }
            }
        } else {
            $this->data['template'] = null;
            $this->data['default_properties'] = true;
            $this->data['editable_mode'] = $settings->get('contribute_properties_editable_mode', 'all');
            if (in_array($this->data['editable_mode'], ['blacklist', 'whitelist'])) {
                $this->data['editable'] = array_intersect_key($propertyIdsByTerms, array_flip($settings->get('contribute_properties_editable', [])));
            }
            $this->data['fillable_mode'] = $settings->get('contribute_properties_fillable_mode', 'all');
            if (in_array($this->data['fillable_mode'], ['blacklist', 'whitelist'])) {
                $this->data['fillable'] = array_intersect_key($propertyIdsByTerms, array_flip($settings->get('contribute_properties_fillable', [])));
            }
        }

        $this->data['is_contributive'] = count($this->data['datatypes_default'])
            || count($this->data['editable'])
            || count($this->data['fillable'])
            || in_array($this->data['editable_mode'], ['all', 'blacklist'])
            || in_array($this->data['fillable_mode'], ['all', 'blacklist']);

        return $this;
    }

    /**
     * @return ArrayObject
     */
    public function data()
    {
        return $this->data;
    }

    /**
     * @return bool
     */
    public function isContributive()
    {
        return $this->data['is_contributive'];
    }

    /**
     * @return bool
     */
    public function hasTemplate()
    {
        return !empty($this->data['template']);
    }

    /**
     * @return \Omeka\Api\Representation\ResourceTemplateRepresentation|null
     */
    public function template()
    {
        return $this->data['template'];
    }

    /**
     * @return bool
     */
    public function useDefaultProperties()
    {
        return $this->data['default_properties'];
    }

    /**
     * @return string
     */
    public function editableMode()
    {
        return $this->data['editable_mode'];
    }

    /**
     * @return array
     */
    public function editableProperties()
    {
        return $this->data['editable'];
    }

    /**
     * @return string
     */
    public function fillableMode()
    {
        return $this->data['fillable_mode'];
    }

    /**
     * @return array
     */
    public function fillableProperties()
    {
        return $this->data['fillable'];
    }

    /**
     * @return array
     */
    public function datatypeProperties()
    {
        return $this->data['datatype'];
    }

    /**
     * @return array
     */
    public function defaultDatatypes()
    {
        return $this->data['datatypes_default'];
    }

    /**
     * @param string
     * @return array
     */
    public function datatypeTerm($term)
    {
        return empty($this->data['datatype'][$term])
            ? $this->data['datatypes_default']
            : $this->data['datatype'][$term];
    }

    /**
     * @param string $term
     * @return bool
     */
    public function isTermContributive($term)
    {
        return $this->isTermEditable($term)
            || $this->isTermFillable($term);
    }

    /**
     * @param string $term
     * @return bool
     */
    public function isTermEditable($term)
    {
        if ($this->hasTemplate()) {
            return isset($this->data['editable'][$term])
                && !empty($this->data['datatype'][$term]);
        }
        return count($this->data['datatypes_default'])
            && (
                ($this->data['editable_mode'] === 'all')
                || ($this->data['editable_mode'] === 'whitelist' && isset($this->data['editable'][$term]))
                || ($this->data['editable_mode'] === 'blacklist' && !isset($this->data['editable'][$term]))
            );
    }

    /**
     * @param string $term
     * @return bool
     */
    public function isTermFillable($term)
    {
        if ($this->hasTemplate()) {
            return isset($this->data['fillable'][$term])
                && !empty($this->data['datatype'][$term]);
        }
        return count($this->data['datatypes_default'])
            && (
                ($this->data['fillable_mode'] === 'all')
                || ($this->data['fillable_mode'] === 'whitelist' && isset($this->data['fillable'][$term]))
                || ($this->data['fillable_mode'] === 'blacklist' && !isset($this->data['fillable'][$term]))
            );
    }

    /**
     * Check if the datatype is managed for the specified term.
     *
     * @param string $term
     * @param string $datatype
     * @return bool
     */
    public function isTermDatatype($term, $datatype)
    {
        if ($this->hasTemplate()) {
            return !empty($this->data['datatype'][$term])
                && in_array($datatype, $this->data['datatype'][$term]);
        }
        return $this->isDefaultDatatype($datatype);
    }

    /**
     * @param string $datatype
     * @return bool
     */
    public function isDefaultDatatype($datatype)
    {
        return in_array($datatype, $this->data['datatypes_default']);
    }

    /**
     * Get the resource template.
     *
     * @var \Omeka\Api\Representation\ResourceTemplateRepresentation|int|string|null $resourceTemplate
     * @return \Omeka\Api\Representation\ResourceTemplateRepresentation|null
     */
    protected function resourceTemplate($resourceTemplate)
    {
        if (empty($resourceTemplate) || is_object($resourceTemplate)) {
            return $resourceTemplate;
        }

        if (is_numeric($resourceTemplate)) {
            try {
                return $this->getView()->api()->read('resource_templates', ['id' => $resourceTemplate])->getContent();
            } catch (\Omeka\Api\Exception\NotFoundException $e) {
                return null;
            }
        }

        if (is_string($resourceTemplate)) {
            return $this->getView()->api()->searchOne('resource_templates', ['label' => $resourceTemplate])->getContent();
        }

        return null;
    }
}

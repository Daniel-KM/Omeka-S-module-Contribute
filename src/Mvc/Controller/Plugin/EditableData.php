<?php
namespace Contribute\Mvc\Controller\Plugin;

use ArrayObject;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Zend\Mvc\Controller\Plugin\AbstractPlugin;

class EditableData extends AbstractPlugin
{
    /**
     * @var \ArrayObject
     */
    protected $data;

    /**
     * Get the editable data (corrigible, fillable, etc.) of a resource.
     *
     *  The list come from the resource template if it is configured, else the
     *  default list is used.
     *
     * @param AbstractResourceEntityRepresentation $resource
     * @return self
     */
    public function __invoke(AbstractResourceEntityRepresentation $resource)
    {
        $this->data = new ArrayObject([
            'isEditable' => false,
            'template' => null,
            'default_properties' => false,
            'corrigible_mode' => 'whitelist',
            'corrigible' => [],
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

        $resourceTemplate = $resource->resourceTemplate();
        if (!$resourceTemplate) {
            $resourceTemplateId = (int) $settings->get('contribute_template_editable');
            if ($resourceTemplateId) {
                try {
                    $resourceTemplate = $controller->api()->read('resource_templates', ['id' => $resourceTemplateId])->getContent();
                } catch (\Omeka\Api\Exception\NotFoundException $e) {
                }
            }
        }

        if ($resourceTemplate) {
            $this->data['template'] = $resourceTemplate;
            $contributePartMap = $controller->resourceTemplateContributePartMap($resourceTemplate->id());
            $this->data['corrigible'] = array_intersect_key($propertyIdsByTerms, array_flip($contributePartMap['corrigible']));
            $this->data['fillable'] = array_intersect_key($propertyIdsByTerms, array_flip($contributePartMap['fillable']));
            foreach ($resourceTemplate->resourceTemplateProperties() as $resourceTemplateProperty) {
                $term = $resourceTemplateProperty->property()->term();
                $datatype = $resourceTemplateProperty->dataType();
                $this->data['datatype'][$term] = $datatype ? [$datatype] : $this->data['datatypes_default'];
            }
        } else {
            $this->data['template'] = null;
            $this->data['default_properties'] = true;
            $this->data['corrigible_mode'] = $settings->get('contribute_properties_corrigible_mode', 'all');
            if (in_array($this->data['corrigible_mode'], ['blacklist', 'whitelist'])) {
                $this->data['corrigible'] = array_intersect_key($propertyIdsByTerms, array_flip($settings->get('contribute_properties_corrigible', [])));
            }
            $this->data['fillable_mode'] = $settings->get('contribute_properties_fillable_mode', 'all');
            if (in_array($this->data['fillable_mode'], ['blacklist', 'whitelist'])) {
                $this->data['fillable'] = array_intersect_key($propertyIdsByTerms, array_flip($settings->get('contribute_properties_fillable', [])));
            }
        }

        $this->data['isEditable'] = count($this->data['datatypes_default'])
            || count($this->data['corrigible'])
            || count($this->data['fillable'])
            || in_array($this->data['corrigible_mode'], ['all', 'blacklist'])
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
    public function isEditable()
    {
        return $this->data['isEditable'];
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
    public function corrigibleMode()
    {
        return $this->data['corrigible_mode'];
    }

    /**
     * @return array
     */
    public function corrigibleProperties()
    {
        return $this->data['corrigible'];
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
    public function isTermEditable($term)
    {
        return $this->isTermCorrigible($term)
            || $this->isTermFillable($term);
    }

    /**
     * @param string $term
     * @return bool
     */
    public function isTermCorrigible($term)
    {
        if ($this->hasTemplate()) {
            return isset($this->data['corrigible'][$term])
                && !empty($this->data['datatype'][$term]);
        }
        return count($this->data['datatypes_default'])
            && (
                ($this->data['corrigible_mode'] === 'all')
                || ($this->data['corrigible_mode'] === 'whitelist' && isset($this->data['corrigible'][$term]))
                || ($this->data['corrigible_mode'] === 'blacklist' && !isset($this->data['corrigible'][$term]))
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
}

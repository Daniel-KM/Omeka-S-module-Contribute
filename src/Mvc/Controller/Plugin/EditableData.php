<?php
namespace Correction\Mvc\Controller\Plugin;

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
        ]);

        $controller = $this->getController();
        $propertyIdsByTerms = $controller->propertyIdsByTerms();
        $settings = $controller->settings();
        $this->data['datatype'] = $settings->get('correction_properties_datatype', []);

        $resourceTemplate = $resource->resourceTemplate();
        if ($resourceTemplate) {
            $this->data['template'] = $resourceTemplate;
            $correctionPartMap = $controller->resourceTemplateCorrectionPartMap($resourceTemplate->id());
            $this->data['corrigible'] = array_intersect_key($propertyIdsByTerms, array_flip($correctionPartMap['corrigible']));
            $this->data['fillable'] = array_intersect_key($propertyIdsByTerms, array_flip($correctionPartMap['fillable']));
        }

        if (!count($this->data['corrigible']) && !count($this->data['fillable'])) {
            $resourceTemplateId = (int) $settings->get('correction_template_editable');
            if ($resourceTemplateId) {
                try {
                    $this->data['template'] = $controller->api()->read('resource_templates', ['id' => $resourceTemplateId])->getContent();
                    $correctionPartMap = $controller->resourceTemplateCorrectionPartMap($resourceTemplateId);
                    $this->data['corrigible'] = array_intersect_key($propertyIdsByTerms, array_flip($correctionPartMap['corrigible']));
                    $this->data['fillable'] = array_intersect_key($propertyIdsByTerms, array_flip($correctionPartMap['fillable']));
                } catch (\Omeka\Api\Exception\NotFoundException $e) {
                    $this->data['template'] = null;
                }
            } else {
                $this->data['template'] = null;
            }

            if (!count($this->data['corrigible']) && !count($this->data['fillable'])) {
                $this->data['template'] = null;
                $this->data['default_properties'] = true;
                $this->data['corrigible_mode'] = $settings->get('correction_properties_corrigible_mode', 'all');
                if (in_array($this->data['corrigible_mode'], ['blacklist', 'whitelist'])) {
                    $this->data['corrigible'] = array_intersect_key($propertyIdsByTerms, array_flip($settings->get('correction_properties_corrigible', [])));
                }
                $this->data['fillable_mode'] = $settings->get('correction_properties_fillable_mode', 'all');
                if (in_array($this->data['fillable_mode'], ['blacklist', 'whitelist'])) {
                    $this->data['fillable'] = array_intersect_key($propertyIdsByTerms, array_flip($settings->get('correction_properties_fillable', [])));
                }
            }
        }

        $this->data['isEditable'] = count($this->data['datatype'])
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
            return isset($this->data['corrigible'][$term]);
        }

        return ($this->data['corrigible_mode'] === 'all')
            || ($this->data['corrigible_mode'] === 'whitelist' && isset($this->data['corrigible'][$term]))
            || ($this->data['corrigible_mode'] === 'blacklist' && !isset($this->data['corrigible'][$term]));
    }

    /**
     * @param string $term
     * @return bool
     */
    public function isTermFillable($term)
    {
        if ($this->hasTemplate()) {
            return isset($this->data['fillable'][$term]);
        }

        return ($this->data['fillable_mode'] === 'all')
            || ($this->data['fillable_mode'] === 'whitelist' && isset($this->data['fillable'][$term]))
            || ($this->data['fillable_mode'] === 'blacklist' && !isset($this->data['fillable'][$term]));
    }

    /**
     * @param string $datatype
     * @return bool
     */
    public function isDatatypeAllowed($datatype)
    {
        if (in_array($datatype, $this->data['datatype'])) {
            return true;
        }
        // TODO Manage resource more precisely.
        if (in_array('resource', $this->data['datatype'])) {
            return strtok($datatype, ':') === 'resource';
        }
        if (in_array('valuesuggest', $this->data['datatype'])) {
            return in_array(strtok($datatype, ':'), ['valuesuggest', 'valuesuggestall']);
        }
        return false;
    }

    /**
     * @return array
     */
    public function datatypes()
    {
        return $this->data['datatype'];
    }
}

<?php
namespace Correction\Mvc\Controller\Plugin;

use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Zend\Mvc\Controller\Plugin\AbstractPlugin;

class ListEditableProperties extends AbstractPlugin
{
    /**
     * Get the list of editable property ids by terms.
     *
     *  The list come from the resource template if it is configured, else the
     *  default list is used.
     *
     * @param AbstractResourceEntityRepresentation $resource
     * @return array
     */
    public function __invoke(AbstractResourceEntityRepresentation $resource)
    {
        $result = [
            'use_default' => false,
            'corrigible' => [],
            'fillable' => [],
        ];

        $controller = $this->getController();
        $propertyIdsByTerms = $controller->propertyIdsByTerms();

        $resourceTemplate = $resource->resourceTemplate();
        if ($resourceTemplate) {
            $correctionPartMap = $controller->resourceTemplateCorrectionPartMap($resourceTemplate->id());
            $result['corrigible'] = array_intersect_key($propertyIdsByTerms, array_flip($correctionPartMap['corrigible']));
            $result['fillable'] = array_intersect_key($propertyIdsByTerms, array_flip($correctionPartMap['fillable']));
        }

        $result['use_default'] = !count($result['corrigible']) && !count($result['fillable']);
        if ($result['use_default']) {
            $settings = $controller->settings();
            $result['corrigible'] = array_intersect_key($propertyIdsByTerms, array_flip($settings->get('correction_properties_corrigible', [])));
            $result['fillable'] = array_intersect_key($propertyIdsByTerms, array_flip($settings->get('correction_properties_fillable', [])));
        }

        return $result;
    }
}

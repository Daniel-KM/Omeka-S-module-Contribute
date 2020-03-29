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
            'default_template' => false,
            'default_properties' => false,
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

        if (!count($result['corrigible']) && !count($result['fillable'])) {
            $settings = $controller->settings();
            $resourceTemplateId = (int) $settings->get('correction_template_editable');
            if ($resourceTemplateId) {
                try {
                    $resourceTemplate = $controller->api()->read('resource_templates', ['id' => $resourceTemplateId])->getContent();
                    $result['default_template'] = $resourceTemplateId;
                    $correctionPartMap = $controller->resourceTemplateCorrectionPartMap($resourceTemplateId);
                    $result['corrigible'] = array_intersect_key($propertyIdsByTerms, array_flip($correctionPartMap['corrigible']));
                    $result['fillable'] = array_intersect_key($propertyIdsByTerms, array_flip($correctionPartMap['fillable']));
                } catch (\Omeka\Api\Exception\NotFoundException $e) {
                    // Nothing to do.
                }
            }

            if (!count($result['corrigible']) && !count($result['fillable'])) {
                $result['default_template'] = false;
                $result['default_properties'] = true;
                $result['corrigible'] = array_intersect_key($propertyIdsByTerms, array_flip($settings->get('correction_properties_corrigible', [])));
                $result['fillable'] = array_intersect_key($propertyIdsByTerms, array_flip($settings->get('correction_properties_fillable', [])));
            }
        }

        return $result;
    }
}

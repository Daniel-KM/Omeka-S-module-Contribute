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
            'is_editable' => false,
            'template' => null,
            'default_properties' => false,
            'corrigible_mode' => 'whitelist',
            'corrigible' => [],
            'fillable_mode' => 'whitelist',
            'fillable' => [],
            'datatype' => [],
        ];

        $controller = $this->getController();
        $propertyIdsByTerms = $controller->propertyIdsByTerms();
        $settings = $controller->settings();
        $result['datatype'] = $settings->get('correction_properties_datatype', []);

        $resourceTemplate = $resource->resourceTemplate();
        if ($resourceTemplate) {
            $result['template'] = $resourceTemplate;
            $correctionPartMap = $controller->resourceTemplateCorrectionPartMap($resourceTemplate->id());
            $result['corrigible'] = array_intersect_key($propertyIdsByTerms, array_flip($correctionPartMap['corrigible']));
            $result['fillable'] = array_intersect_key($propertyIdsByTerms, array_flip($correctionPartMap['fillable']));
        }

        if (!count($result['corrigible']) && !count($result['fillable'])) {
            $resourceTemplateId = (int) $settings->get('correction_template_editable');
            if ($resourceTemplateId) {
                try {
                    $result['template'] = $controller->api()->read('resource_templates', ['id' => $resourceTemplateId])->getContent();
                    $correctionPartMap = $controller->resourceTemplateCorrectionPartMap($resourceTemplateId);
                    $result['corrigible'] = array_intersect_key($propertyIdsByTerms, array_flip($correctionPartMap['corrigible']));
                    $result['fillable'] = array_intersect_key($propertyIdsByTerms, array_flip($correctionPartMap['fillable']));
                } catch (\Omeka\Api\Exception\NotFoundException $e) {
                    $result['template'] = null;
                }
            } else {
                $result['template'] = null;
            }

            if (!count($result['corrigible']) && !count($result['fillable'])) {
                $result['template'] = null;
                $result['default_properties'] = true;
                $result['corrigible_mode'] = $settings->get('correction_properties_corrigible_mode', 'all');
                if (in_array($result['corrigible_mode'], ['blacklist', 'whitelist'])) {
                    $result['corrigible'] = array_intersect_key($propertyIdsByTerms, array_flip($settings->get('correction_properties_corrigible', [])));
                }
                $result['fillable_mode'] = $settings->get('correction_properties_fillable_mode', 'all');
                if (in_array($result['fillable_mode'], ['blacklist', 'whitelist'])) {
                    $result['fillable'] = array_intersect_key($propertyIdsByTerms, array_flip($settings->get('correction_properties_fillable', [])));
                }
            }
        }

        $result['is_editable'] = count($result['datatype'])
            || count($result['corrigible'])
            || count($result['fillable'])
            || in_array($result['corrigible_mode'], ['all', 'blacklist'])
            || in_array($result['fillable_mode'], ['all', 'blacklist']);

        return $result;
    }
}

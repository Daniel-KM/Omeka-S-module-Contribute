<?php
namespace Contribute\Mvc\Controller\Plugin;

use Zend\Mvc\Controller\Plugin\AbstractPlugin;

class ResourceTemplateContributePartMap extends AbstractPlugin
{
    /**
     * Get the contribute mapping of a resource template.
     *
     * @todo Add these values directly in the json of the resource template via an event.
     *
     * @param int $resourceTemplateId
     * @return array
     */
    public function __invoke($resourceTemplateId)
    {
        $settings = $this->getController()->settings();

        $mapping = $settings->get('contribute_resource_template_data', []);

        $corrigible = empty($mapping['corrigible'][$resourceTemplateId])
            ? []
            : $mapping['corrigible'][$resourceTemplateId];
        $fillable = empty($mapping['fillable'][$resourceTemplateId])
            ? []
            : $mapping['fillable'][$resourceTemplateId];

        return [
            'corrigible' => $corrigible,
            'fillable' => $fillable,
        ];
    }
}

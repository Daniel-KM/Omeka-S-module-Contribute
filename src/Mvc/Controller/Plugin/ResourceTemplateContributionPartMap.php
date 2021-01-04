<?php declare(strict_types=1);
namespace Contribute\Mvc\Controller\Plugin;

use Laminas\Mvc\Controller\Plugin\AbstractPlugin;

class ResourceTemplateContributionPartMap extends AbstractPlugin
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

        $editable = empty($mapping['editable'][$resourceTemplateId])
            ? []
            : $mapping['editable'][$resourceTemplateId];
        $fillable = empty($mapping['fillable'][$resourceTemplateId])
            ? []
            : $mapping['fillable'][$resourceTemplateId];

        return [
            'editable' => $editable,
            'fillable' => $fillable,
        ];
    }
}

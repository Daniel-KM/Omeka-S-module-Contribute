<?php declare(strict_types=1);

namespace Contribute\Site\ResourcePageBlockLayout;

use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Site\ResourcePageBlockLayout\ResourcePageBlockLayoutInterface;

class ContributionLink implements ResourcePageBlockLayoutInterface
{
    public function getLabel() : string
    {
        return 'Contribute: Edit link'; // @translate
    }

    public function getCompatibleResourceNames() : array
    {
        return [
            'items',
            // 'media',
        ];
    }

    public function render(PhpRenderer $view, AbstractResourceEntityRepresentation $resource) : string
    {
        return $view->partial('common/resource-page-block-layout/contribution-link', [
            'resource' => $resource,
        ]);
    }
}

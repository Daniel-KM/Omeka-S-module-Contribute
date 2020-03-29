<?php

namespace Correction\View\Helper;

use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Zend\View\Helper\AbstractHelper;

class LinkCorrection extends AbstractHelper
{
    /**
     * Get the link to the correction page.
     *
     * @return string
     */
    public function __invoke(AbstractResourceEntityRepresentation $resource = null)
    {
        $view = $this->getView();

        // TODO Check if the user has right to see it.

        if (empty($resource)) {
            $resource = $view->resource;
            if (empty($resource)) {
                return '';
            }
        }

        return $view->partial('common/helper/correction-link', [
            'site' => $this->currentSite(),
            'resource' => $resource,
        ]);
    }

    /**
     * Get the current site from the view.
     *
     * @return \Omeka\Api\Representation\SiteRepresentation|null
     */
    protected function currentSite()
    {
        $view = $this->getView();
        return isset($view->site)
            ? $view->site
            : $view->getHelperPluginManager()->get('Zend\View\Helper\ViewModel')->getRoot()->getVariable('site');
    }
}

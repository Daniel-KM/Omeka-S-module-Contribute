<?php

namespace Correction\View\Helper;

use Correction\Mvc\Controller\Plugin\CheckToken;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Zend\View\Helper\AbstractHelper;

class LinkCorrection extends AbstractHelper
{
    /**
     * @var CheckToken
     */
    protected $checkToken;

    public function __construct(CheckToken $checkToken)
    {
        $this->checkToken = $checkToken;
    }

    /**
     * Get the link to the correction page.
     *
     * @return string
     */
    public function __invoke(AbstractResourceEntityRepresentation $resource = null)
    {
        $view = $this->getView();

        if (empty($resource)) {
            $resource = $view->resource;
            if (empty($resource)) {
                return '';
            }
        }

        $user = $view->identity();

        $helper = $this->checkToken;
        $canCorrect = (bool) $helper($resource)
            || ($user && $view->setting('correction_without_token'));

        return $view->partial('common/helper/correction-link', [
            'site' => $this->currentSite(),
            'user' => $user,
            'resource' => $resource,
            'canCorrect' => $canCorrect,
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

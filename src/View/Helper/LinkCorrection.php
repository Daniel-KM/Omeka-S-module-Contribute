<?php
namespace Contribute\View\Helper;

use Contribute\Mvc\Controller\Plugin\CheckToken;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Zend\View\Helper\AbstractHelper;

class LinkContribute extends AbstractHelper
{
    /**
     * The default partial view script.
     */
    const PARTIAL_NAME = 'common/helper/contribute-link';

    /**
     * @var CheckToken
     */
    protected $checkToken;

    public function __construct(CheckToken $checkToken)
    {
        $this->checkToken = $checkToken;
    }

    /**
     * Get the link to the contribute page.
     *
     * @param AbstractResourceEntityRepresentation $resource
     * @param array $options Options for the template
     * @return string
     */
    public function __invoke(AbstractResourceEntityRepresentation $resource = null, array $options = [])
    {
        $view = $this->getView();

        if (empty($resource)) {
            $resource = $view->resource;
            if (empty($resource)) {
                return '';
            }
        }

        $defaultOptions = [
            'template' => self::PARTIAL_NAME,
        ];
        $options += $defaultOptions;

        $user = $view->identity();

        $helper = $this->checkToken;
        $canCorrect = (bool) $helper($resource)
            || ($user && $view->setting('contribute_without_token'));

        $template = $options['template'];
        unset($options['template']);

        $vars = [
            'site' => $this->currentSite(),
            'user' => $user,
            'resource' => $resource,
            'canCorrect' => $canCorrect,
        ] + $options;

        return $view->partial($template, $vars);
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

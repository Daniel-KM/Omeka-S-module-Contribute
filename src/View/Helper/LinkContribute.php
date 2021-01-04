<?php declare(strict_types=1);
namespace Contribute\View\Helper;

use Contribute\Mvc\Controller\Plugin\CheckToken;
use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

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
     * Get the link to the contribute page (add if no resource, else edit).
     *
     * @param AbstractResourceEntityRepresentation $resource If empty, the view
     * is checked.
     * @param array $options Options for the template
     * @return string
     */
    public function __invoke(AbstractResourceEntityRepresentation $resource = null, array $options = [])
    {
        $view = $this->getView();

        if (empty($resource)) {
            $resource = $view->resource;
        }

        $defaultOptions = [
            'template' => self::PARTIAL_NAME,
        ];
        $options += $defaultOptions;

        $user = $view->identity();

        $helper = $this->checkToken;
        $canEdit = ($resource && $helper($resource))
            || ($user && $view->setting('contribute_without_token'));

        $template = $options['template'];
        unset($options['template']);

        $vars = [
            'site' => $this->currentSite(),
            'user' => $user,
            'resource' => $resource,
            'canEdit' => $canEdit,
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
            : $view->getHelperPluginManager()->get('Laminas\View\Helper\ViewModel')->getRoot()->getVariable('site');
    }
}

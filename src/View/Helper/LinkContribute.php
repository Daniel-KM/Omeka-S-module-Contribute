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
    const PARTIAL_NAME = 'common/contribute-link';

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
        $setting = $view->plugin('setting');
        $contributeMode = $setting('contribute_mode');
        $contributeRoles = $setting('contribute_roles', []) ?: [];

        $canEdit = ($resource && $this->checkToken->__invoke($resource))
            || $contributeMode === 'open'
            || ($user && $contributeMode === 'user')
            || ($user && $contributeMode === 'role' && in_array($user->getRole(), $contributeRoles));

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
     */
    protected function currentSite(): ?\Omeka\Api\Representation\SiteRepresentation
    {
        return $this->view->site ?? $this->view->site = $this->view
            ->getHelperPluginManager()
            ->get('Laminas\View\Helper\ViewModel')
            ->getRoot()
            ->getVariable('site');
    }
}

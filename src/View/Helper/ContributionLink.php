<?php declare(strict_types=1);

namespace Contribute\View\Helper;

use Contribute\Mvc\Controller\Plugin\CheckToken;
use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

class ContributionLink extends AbstractHelper
{
    /**
     * The default partial view script.
     */
    const PARTIAL_NAME = 'common/contribution-link';

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
     * @todo Don't check the view.
     *
     * @param AbstractResourceEntityRepresentation $resource If empty, the view
     * is checked.
     * @param array $options Options for the template
     *   - urlOnly (bool)
     *   - template (string)
     *   - space (string) "default" or "guest"
     * @return string
     */
    public function __invoke(?AbstractResourceEntityRepresentation $resource = null, array $options = []): string
    {
        $view = $this->getView();

        if (empty($resource)) {
            $resource = $view->resource ?? null;
        }

        $defaultOptions = [
            'template' => self::PARTIAL_NAME,
            'space' => 'default',
        ];
        $options += $defaultOptions;

        $plugins = $view->getHelperPluginManager();
        $user = $plugins->get('identity')();
        $setting = $plugins->get('setting');
        $contributeMode = $setting('contribute_mode');
        $contributeRoles = $setting('contribute_roles', []) ?: [];

        $canEdit = ($resource && $this->checkToken->__invoke($resource))
            || $contributeMode === 'open'
            || ($user && $contributeMode === 'user')
            || ($user && $contributeMode === 'role' && in_array($user->getRole(), $contributeRoles));

        $isEditable = false;
        if ($resource) {
            $resourceTemplate = $resource->resourceTemplate();
            if ($resourceTemplate) {
                $isEditable = in_array($resourceTemplate->id(), $setting('contribute_templates', []));
            }
        } else {
            $isEditable = !empty($setting('contribute_templates', []));
        }

        $template = $options['template'];
        unset($options['template']);

        $vars = [
            'site' => $this->currentSite(),
            'user' => $user,
            'resource' => $resource,
            'canEdit' => $canEdit,
            'isEditable' => $isEditable,
        ] + $options;

        $asGuest = $vars['space'] === 'guest';

        if (!empty($options['urlOnly'])) {
            if (!$isEditable) {
                return '';
            }
            $url = $plugins->get('url');
            // Existing resource.
            if ($resource) {
                if ($canEdit) {
                    return $url($asGuest ? 'site/guest/contribution-id' : 'site/contribution-id', ['resource' => $resource->getControllerName(), 'id' => $resource->id(), 'action' => 'edit'], true);
                } elseif ($user) {
                    return '';
                } else {
                    return $plugins->has('guestWidget') ? $url('site/guest/anonymous', ['action' => 'login'], true) : $url('login');
                }
            } else {
                // New resource.
                if ($canEdit) {
                    return $url($asGuest ? 'site/guest/contribution' : 'site/contribution', [], true);
                } elseif ($user) {
                    return '';
                } else {
                    return $plugins->has('guestWidget') ? $url('site/guest/anonymous', ['action' => 'login'], true) : $url('login');
                }
            }
        }

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

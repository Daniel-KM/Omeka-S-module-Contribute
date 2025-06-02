<?php declare(strict_types=1);

namespace Contribute\Site\Navigation\Link;

use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Site\Navigation\Link\LinkInterface;
use Omeka\Stdlib\ErrorStore;

class Contributions implements LinkInterface
{
    public function getName()
    {
        return 'Contributions'; // @translate
    }

    public function getFormTemplate()
    {
        return 'common/navigation-link-form/label';
    }

    public function isValid(array $data, ErrorStore $errorStore)
    {
        return true;
    }

    public function getLabel(array $data, SiteRepresentation $site)
    {
        return isset($data['label']) && trim($data['label']) !== ''
            ? $data['label']
            : 'Contributions'; // @translate
    }

    public function toZend(array $data, SiteRepresentation $site)
    {
        // Unlike Selection, the link is only available for authenticated users.

        /**
         * @var \Omeka\Entity\User $user
         * @var \Omeka\Module\Manager $moduleManager
         * @var \Contribute\View\Helper\CanContribute $canContribute
         */

        $services = $site->getServiceLocator();
        $moduleManager = $services->get('Omeka\ModuleManager');
        $module = $moduleManager->getModule('Guest');
        $isGuestActive = $module
            && $module->getState() === \Omeka\Module\Manager::STATE_ACTIVE;
        $siteSlug = $site->slug();

        if (!$isGuestActive) {
            // Hide: just set the route and resource without privilege.
            return [
                'route' => 'site/guest/contribution',
                'params' => [
                    'site-slug' => $siteSlug,
                    'action' => 'browse',
                ],
                'resource' => 'no-privilege',
            ];
        }

        $user = $services->get('Omeka\AuthenticationService')->getIdentity();
        if (!$user) {
            // Try to login first.
            return [
                'label' => $data['label'],
                'route' => 'site/guest/anonymous',
                'class' => 'contribution-link',
                'params' => [
                    'site-slug' => $siteSlug,
                    'action' => 'login',
                ],
            ];
        }

        $viewHelpers = $services->get('ViewHelperManager');
        $canContribute = $viewHelpers->get('canContribute');
        if (!$canContribute()) {
            // Hide: just set the route and resource without privilege.
            return [
                'route' => 'site/guest/contribution',
                'params' => [
                    'site-slug' => $siteSlug,
                    'action' => 'browse',
                ],
                'resource' => 'no-privilege',
            ];
        }

        return [
            'label' => $data['label'],
            'route' => 'site/guest/contribution',
            'class' => 'contribution-link',
            'params' => [
                'site-slug' => $siteSlug,
                'action' => 'browse',
            ],
            // The controller has no rights defined in acl.
            // 'resource' => 'Contribute\Controller\Site\GuestBoard',
        ];
    }

    public function toJstree(array $data, SiteRepresentation $site)
    {
        return [
            'label' => isset($data['label']) ? trim($data['label']) : '',
        ];
    }
}

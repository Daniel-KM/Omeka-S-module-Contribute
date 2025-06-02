<?php declare(strict_types=1);

namespace Contribute\Site\Navigation\Link;

use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Site\Navigation\Link\LinkInterface;
use Omeka\Stdlib\ErrorStore;

class Contribute implements LinkInterface
{
    public function getName()
    {
        return 'Contribution: new'; // @translate
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
            : 'Contribute'; // @translate
    }

    public function toZend(array $data, SiteRepresentation $site)
    {
        /**
         * @var \Omeka\Entity\User $user
         * @var \Omeka\Module\Manager $moduleManager
         * @var \Contribute\View\Helper\CanContribute $canContribute
         */
        $services = $site->getServiceLocator();
        $user = $services->get('Omeka\AuthenticationService')->getIdentity();
        $viewHelpers = $services->get('ViewHelperManager');
        $canContribute = $viewHelpers->get('canContribute');
        $moduleManager = $services->get('Omeka\ModuleManager');
        $module = $moduleManager->getModule('Guest');
        $isGuestActive = $module
            && $module->getState() === \Omeka\Module\Manager::STATE_ACTIVE;
        $siteSlug = $site->slug();

        if (!$canContribute()) {
            if ($user) {
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
            // Try to login first.
            return [
                'label' => $data['label'],
                'route' => 'site/guest/anonymous',
                'class' => 'contribute-link',
                'params' => [
                    'site-slug' => $siteSlug,
                    'action' => 'login',
                ],
            ];
        }

        // For simplicity, no check is done in the menu for now.
        // $contributionLink = $viewHelpers->get('contributionLink');

        // There is a specific link for guest.
        if ($user && $isGuestActive) {
            return [
                'label' => $data['label'],
                'route' => 'site/guest/contribution',
                'class' => 'contribute-link',
                'params' => [
                    'site-slug' => $siteSlug,
                    'resource' => 'item',
                    'action' => 'add',
                ],
                // 'resource' => 'Contribute\Controller\Site\GuestBoard',
            ];
        }

        return [
            'label' => $data['label'],
            'route' => 'site/contribution',
            'class' => 'contribute-link',
            'params' => [
                'site-slug' => $siteSlug,
                'resource' => 'item',
                'action' => 'add',
            ],
            // The controller has no rights defined in acl.
            // 'resource' => 'Contribute\Controller\Site\Contribution',
        ];
    }

    public function toJstree(array $data, SiteRepresentation $site)
    {
        return [
            'label' => isset($data['label']) ? trim($data['label']) : '',
        ];
    }
}
